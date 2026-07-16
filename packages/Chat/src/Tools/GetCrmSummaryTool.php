<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Support\ChatEntities;

final class GetCrmSummaryTool implements Tool
{
    public function description(): string
    {
        return 'Get a summary of CRM data: record counts and recent activity.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;

        $summary = [
            'record_counts' => [
                'companies' => Company::query()->whereBelongsTo($team)->count(),
                'people' => People::query()->whereBelongsTo($team)->count(),
                'opportunities' => Opportunity::query()->whereBelongsTo($team)->count(),
                'tasks' => Task::query()->whereBelongsTo($team)->count(),
                'notes' => Note::query()->whereBelongsTo($team)->count(),
                ...$this->extraCounts($team),
            ],
            'recent_activity' => [
                'companies_this_week' => Company::query()->whereBelongsTo($team)->where('created_at', '>=', now()->startOfWeek())->count(),
                'tasks_this_week' => Task::query()->whereBelongsTo($team)->where('created_at', '>=', now()->startOfWeek())->count(),
                'opportunities_this_week' => Opportunity::query()->whereBelongsTo($team)->where('created_at', '>=', now()->startOfWeek())->count(),
            ],
        ];

        return (string) json_encode($summary, JSON_PRETTY_PRINT);
    }

    /**
     * Recuentos de las entidades que aportan los addons (seam config('chat.extra_entities')).
     *
     * @return array<string, int>
     */
    private function extraCounts(Team $team): array
    {
        $counts = [];

        foreach (ChatEntities::all() as $entity) {
            $counts[$entity->key()] = $entity->count($team);
        }

        return $counts;
    }
}
