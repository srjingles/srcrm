<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use SrJingles\SrCrm\Enums\TaskStatus;

/*
 | Automatismos del vocabulario de estados (addon srjingles/sr-crm), contra BD real:
 |
 |  1. Al entrar el estado en "Enviada al cliente", el StatusAutomationObserver
 |     incrementa el campo `client_round`. Es lo que sustituye a modelar cada entrega
 |     como un estado propio.
 |  2. Al cambiar la fecha de vencimiento, el estado se mueve entre "Sin planificar" y
 |     "Por hacer" —y NUNCA pisa un estado que ya arrancó.
 |
 | Las reglas puras están cubiertas en la suite del addon (StatusVocabularyTest); lo que
 | se fija aquí es el cableado: que el observer se dispare, resuelva las opciones del
 | equipo correcto y escriba, sin reentrar en sí mismo.
 */

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->personalTeam();
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
});

/** Custom field del equipo por code. */
function taskField(string $code): CustomField
{
    return CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', test()->team->id)
        ->where('entity_type', 'task')
        ->where('code', $code)
        ->firstOrFail();
}

/** Id de la opción de estado con esa etiqueta. */
function statusOption(TaskStatus $status): string
{
    return (string) taskField('status')->options()
        ->withoutGlobalScopes()
        ->where('name', $status->label())
        ->firstOrFail()
        ->getKey();
}

/** Valor crudo que tiene la tarea en un campo. */
function taskValue(Task $task, string $code): mixed
{
    $field = taskField($code);

    return $task->customFieldValues()
        ->withoutGlobalScopes()
        ->where('custom_field_id', $field->getKey())
        ->first()
        ?->getAttribute($field->getValueColumn());
}

it('siembra el vocabulario nuevo de estados al crear el equipo', function (): void {
    $opciones = taskField('status')->options()
        ->withoutGlobalScopes()
        ->orderBy('sort_order')
        ->pluck('name')
        ->all();

    // En orden de flujo, no en el orden en que se fueron creando.
    expect($opciones)->toBe(TaskStatus::options());
});

it('cuenta la vuelta al enviar la tarea al cliente', function (): void {
    $task = Task::factory()->for($this->team)->create();

    $task->saveCustomFieldValue(taskField('status'), statusOption(TaskStatus::WithClient));

    expect(taskValue($task, 'client_round'))->toBe(1);
});

it('cuenta una vuelta más en cada entrega nueva', function (): void {
    $task = Task::factory()->for($this->team)->create();

    $task->saveCustomFieldValue(taskField('status'), statusOption(TaskStatus::WithClient));
    $task->saveCustomFieldValue(taskField('status'), statusOption(TaskStatus::InProgress));
    $task->saveCustomFieldValue(taskField('status'), statusOption(TaskStatus::WithClient));

    expect(taskValue($task, 'client_round'))->toBe(2);
});

it('no infla la vuelta al reguardar el mismo estado', function (): void {
    $task = Task::factory()->for($this->team)->create();

    $task->saveCustomFieldValue(taskField('status'), statusOption(TaskStatus::WithClient));
    $task->saveCustomFieldValue(taskField('status'), statusOption(TaskStatus::WithClient));

    expect(taskValue($task, 'client_round'))->toBe(1);
});

it('marca sin planificar la tarea que se queda sin fecha de vencimiento', function (): void {
    $task = Task::factory()->for($this->team)->create();
    $task->saveCustomFieldValue(taskField('status'), statusOption(TaskStatus::Todo));

    $task->saveCustomFieldValue(taskField('due_date'), null);

    expect(taskValue($task, 'status'))->toBe(statusOption(TaskStatus::Unplanned));
});

it('saca de sin planificar en cuanto se pone fecha', function (): void {
    $task = Task::factory()->for($this->team)->create();
    $task->saveCustomFieldValue(taskField('status'), statusOption(TaskStatus::Unplanned));

    $task->saveCustomFieldValue(taskField('due_date'), now()->addWeek());

    expect(taskValue($task, 'status'))->toBe(statusOption(TaskStatus::Todo));
});

it('no retrocede una tarea ya empezada aunque se borre la fecha', function (): void {
    // La garantía que hace tolerable el automatismo: no le deshace el trabajo a nadie.
    $task = Task::factory()->for($this->team)->create();
    $task->saveCustomFieldValue(taskField('status'), statusOption(TaskStatus::InProgress));

    $task->saveCustomFieldValue(taskField('due_date'), null);

    expect(taskValue($task, 'status'))->toBe(statusOption(TaskStatus::InProgress));
});

it('respeta el interruptor de configuración', function (): void {
    config()->set('srcrm.statuses.automations', false);

    $task = Task::factory()->for($this->team)->create();
    $task->saveCustomFieldValue(taskField('status'), statusOption(TaskStatus::WithClient));

    expect(taskValue($task, 'client_round'))->toBeNull();
});
