<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Data\DigestPayload;
use App\Data\DigestTaskItem;
use App\Data\DigestTeamSection;
use App\Filament\Resources\TaskResource;
use App\Models\Team;
use App\Models\User;
use App\Support\Tasks\ClosedTaskStatuses;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final readonly class DigestService
{
    public function forUser(User $user): DigestPayload
    {
        $timezone = $user->timezone ?? (string) config('app.timezone');
        $startOfToday = Date::now($timezone)->startOfDay()->utc();
        $windowEnd = $startOfToday->copy()->addDay();

        $sections = [];

        foreach ($user->allTeams() as $team) {
            $section = $this->sectionForTeam($user, $team, $startOfToday, $windowEnd);

            if (! $section->isEmpty()) {
                $sections[] = $section;
            }
        }

        return new DigestPayload($sections);
    }

    private function sectionForTeam(User $user, Team $team, Carbon $startOfToday, Carbon $windowEnd): DigestTeamSection
    {
        $meta = $this->resolveFieldMetadata($team);

        if ($meta['due_field_id'] === null) {
            return new DigestTeamSection($team->name, [], []);
        }

        $rows = DB::table('tasks as t')
            ->join('task_user as tu', 'tu.task_id', '=', 't.id')
            ->leftJoin('custom_field_values as due', function (JoinClause $join) use ($meta): void {
                $join->on('due.entity_id', '=', 't.id')
                    ->where('due.entity_type', '=', 'task')
                    ->where('due.custom_field_id', '=', $meta['due_field_id']);
            })
            ->where('t.team_id', $team->getKey())
            ->where('tu.user_id', $user->getKey())
            ->whereNull('t.deleted_at')
            ->whereNotNull('due.datetime_value')
            ->where('due.datetime_value', '<', $windowEnd)
            ->when($meta['closed_option_ids'] !== [], function (Builder $query) use ($meta): void {
                $query->whereNotExists(function (Builder $sub) use ($meta): void {
                    $sub->select(DB::raw(1))
                        ->from('custom_field_values as st')
                        ->whereColumn('st.entity_id', 't.id')
                        ->where('st.entity_type', 'task')
                        ->where('st.custom_field_id', $meta['status_field_id'])
                        ->whereIn('st.string_value', $meta['closed_option_ids']);
                });
            })
            ->orderBy('due.datetime_value')
            ->select(['t.id', 't.title', 'due.datetime_value as due_at'])
            ->get();

        $tasksIndexUrl = TaskResource::getUrl(name: 'index', parameters: ['tenant' => $team], panel: 'app');

        $overdue = [];
        $upcoming = [];

        foreach ($rows as $row) {
            $dueAt = Date::parse($row->due_at);

            $item = new DigestTaskItem(
                title: (string) $row->title,
                dueAt: $dueAt,
                editUrl: $tasksIndexUrl.'?'.http_build_query([
                    'tableAction' => 'edit',
                    'tableActionRecord' => (string) $row->id,
                ]),
            );

            if ($dueAt->lt($startOfToday)) {
                $overdue[] = $item;
            } else {
                $upcoming[] = $item;
            }
        }

        return new DigestTeamSection($team->name, $overdue, $upcoming);
    }

    /**
     * @return array{due_field_id: ?string, status_field_id: ?string, closed_option_ids: list<string>}
     */
    private function resolveFieldMetadata(Team $team): array
    {
        $row = DB::table('custom_fields as cf')
            ->where('cf.tenant_id', $team->getKey())
            ->where('cf.entity_type', 'task')
            ->whereIn('cf.code', ['due_date', 'status'])
            ->selectRaw(implode(', ', [
                "MAX(CASE WHEN cf.code = 'due_date' THEN cf.id END) AS due_field_id",
                "MAX(CASE WHEN cf.code = 'status' THEN cf.id END) AS status_field_id",
            ]))
            ->first();

        $statusFieldId = $row?->status_field_id !== null ? (string) $row->status_field_id : null;

        return [
            'due_field_id' => $row?->due_field_id !== null ? (string) $row->due_field_id : null,
            'status_field_id' => $statusFieldId,
            'closed_option_ids' => self::closedOptionIds($statusFieldId),
        ];
    }

    /**
     * Ids de las opciones de estado que cuentan como terminales, en este equipo.
     *
     * Se resuelven por ETIQUETA (config/tasks.php) y no contra una cadena incrustada:
     * el juego de estados es redefinible por equipo o por addon, y comparar con "Done"
     * a pelo deja de excluir nada en silencio en cuanto alguien lo renombra —el resumen
     * diario se pondría a listar tareas ya terminadas—.
     *
     * @return list<string>
     */
    private static function closedOptionIds(?string $statusFieldId): array
    {
        if ($statusFieldId === null) {
            return [];
        }

        return DB::table('custom_field_options')
            ->where('custom_field_id', $statusFieldId)
            ->whereIn('name', ClosedTaskStatuses::labels())
            ->pluck('id')
            ->map(strval(...))
            ->all();
    }
}
