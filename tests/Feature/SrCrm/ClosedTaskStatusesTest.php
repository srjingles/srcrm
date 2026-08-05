<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Task;
use App\Models\User;
use App\Support\Tasks\ClosedTaskStatuses;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Data\MyTaskItem;
use Relaticle\Chat\Services\MyTasksService;
use SrJingles\SrCrm\Enums\TaskStatus;

/*
 | Guardián del seam `tasks.closed_status_labels`.
 |
 | El resumen diario por correo (DigestService) y "mis tareas" del chat (MyTasksService)
 | excluyen el trabajo ya cerrado, y lo hacían comparando la etiqueta de la opción de estado
 | con la cadena 'Done' incrustada en el código. Al redefinir el addon el vocabulario
 | ('Completada'), esa comparación dejaba de casar y los dos servicios dejaban de excluir
 | NADA —sin error ninguno: el correo diario se ponía a listar tareas terminadas—.
 |
 | Además el host resolvía UNA sola opción terminal, así que 'Cancelada' no cabía siquiera.
 | Ahora las etiquetas salen de config y el addon las fija con su propio juego.
 */

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);

    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->personalTeam();
});

/** Id del custom field de tarea con ese code, en el equipo del test. */
function fieldIdFor(string $code): string
{
    return trim((string) DB::table('custom_fields')
        ->where('tenant_id', test()->team->getKey())
        ->where('entity_type', 'task')
        ->where('code', $code)
        ->value('id'));
}

/** Crea una tarea del equipo con estado y vencimiento dados. */
function taskWithStatus(TaskStatus $status, ?string $dueAt = '-1 day'): Task
{
    $task = Task::factory()->for(test()->team)->create();
    $task->assignees()->attach(test()->owner);

    $optionId = DB::table('custom_field_options')
        ->where('custom_field_id', fieldIdFor('status'))
        ->where('name', $status->label())
        ->value('id');

    DB::table('custom_field_values')->insert([
        'id' => (string) Str::ulid(),
        'tenant_id' => test()->team->getKey(),
        'entity_type' => 'task',
        'entity_id' => $task->getKey(),
        'custom_field_id' => fieldIdFor('status'),
        'string_value' => $optionId,
    ]);

    if ($dueAt !== null) {
        DB::table('custom_field_values')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => test()->team->getKey(),
            'entity_type' => 'task',
            'entity_id' => $task->getKey(),
            'custom_field_id' => fieldIdFor('due_date'),
            'datetime_value' => now()->modify($dueAt),
        ]);
    }

    return $task;
}

it('declara como terminales los estados del addon, no la cadena del host', function (): void {
    // El addon empuja su vocabulario en register(); sin él, el default sigue siendo 'Done'.
    expect(ClosedTaskStatuses::labels())->toBe(['Completada', 'Cancelada'])
        ->and(ClosedTaskStatuses::labels())->toBe(TaskStatus::closedLabels());
});

it('el chat excluye de "mis tareas" tanto las completadas como las canceladas', function (): void {
    $viva = taskWithStatus(TaskStatus::InProgress);
    taskWithStatus(TaskStatus::Completed);
    taskWithStatus(TaskStatus::Cancelled);

    $ids = resolve(MyTasksService::class)
        ->forUser($this->owner, $this->team)
        ->map(fn (MyTaskItem $item): string => $item->id)
        ->all();

    expect($ids)->toBe([(string) $viva->getKey()]);
});

it('cae al estado de serie si la config queda vacía', function (): void {
    // Nunca debe quedarse sin etiquetas: sin ninguna, la consulta dejaría de excluir.
    config()->set('tasks.closed_status_labels', []);

    expect(ClosedTaskStatuses::labels())->toBe(['Done']);
});
