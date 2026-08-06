<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;
use SrJingles\SrCrm\Models\TimeEntry;

/*
 | El historial de actividad ya registra los cambios de tiempo por sí solo: el acumulador
 | escribe el campo `time_log` en cada alta, edición y borrado de un apunte, y el
 | CustomFieldValueObserver del host emite un `custom_field_changes` contra la Tarea. Eso es
 | lo que sale en su línea de tiempo, y es el rastro que disuade de "ajustar" horas.
 |
 | Lo que faltaba era que se LEYERA: el observer guardaba el valor crudo, así que rebajar
 | una tarea de 5h a 3h aparecía como «300 → 180». Un rastro que nadie lee no disuade de
 | nada. El seam `custom-fields.value_labelers` deja que el tipo de campo aporte su etiqueta.
 */

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->personalTeam();
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
});

/** Último `custom_field_changes` registrado contra la tarea. */
function ultimoCambio(Task $task): ?array
{
    $activity = Activity::query()
        ->where('subject_type', $task->getMorphClass())
        ->where('subject_id', $task->getKey())
        ->where('event', 'custom_field_changes')
        ->latest('id')
        ->first();

    return $activity?->properties['custom_field_changes'][0] ?? null;
}

it('registra el cambio de tiempo en horas legibles, no en minutos crudos', function (): void {
    $task = Task::factory()->for($this->team)->create();

    $entry = new TimeEntry(['user_id' => $this->owner->getKey(), 'minutes' => 300, 'worked_on' => now()]);
    $entry->timeable()->associate($task);
    $entry->save();

    $entry->update(['minutes' => 180]);

    $cambio = ultimoCambio($task);

    expect($cambio)->not->toBeNull()
        ->and($cambio['code'])->toBe('time_log')
        ->and($cambio['old']['label'])->toBe('5h')
        ->and($cambio['new']['label'])->toBe('3h');
});

it('registra también el borrado de un apunte', function (): void {
    $task = Task::factory()->for($this->team)->create();

    $entry = new TimeEntry(['user_id' => $this->owner->getKey(), 'minutes' => 90, 'worked_on' => now()]);
    $entry->timeable()->associate($task);
    $entry->save();

    $entry->delete();

    $cambio = ultimoCambio($task);

    expect($cambio['old']['label'])->toBe('1h 30m')
        ->and($cambio['new']['label'])->toBe('—');
});

it('resuelve el nombre del miembro en un choice de opciones dinámicas', function (): void {
    // team_member calcula sus opciones en runtime, así que no casa con ninguna fila de
    // custom_field_options: sin preguntar al proveedor dinámico, la línea de tiempo
    // enseñaría el ULID crudo.
    $miembro = User::factory()->create(['name' => 'Ana Responsable']);
    $this->team->users()->attach($miembro, ['role' => 'editor']);

    $field = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'sales_owner')
        ->first();

    expect($field)->not->toBeNull();

    $opportunity = Opportunity::factory()->for($this->team)->create();
    $opportunity->saveCustomFieldValue($field, (string) $miembro->getKey());

    $activity = Activity::query()
        ->where('subject_type', $opportunity->getMorphClass())
        ->where('subject_id', $opportunity->getKey())
        ->where('event', 'custom_field_changes')
        ->latest('id')
        ->first();

    $cambio = $activity?->properties['custom_field_changes'][0] ?? null;

    expect($cambio)->not->toBeNull()
        ->and($cambio['new']['label'])->toBe('Ana Responsable')
        ->and($cambio['new']['label'])->not->toBe((string) $miembro->getKey());
});

it('deja el valor crudo si nadie registra etiquetador para el tipo', function (): void {
    config()->set('custom-fields.value_labelers', []);

    $task = Task::factory()->for($this->team)->create();

    $entry = new TimeEntry(['user_id' => $this->owner->getKey(), 'minutes' => 300, 'worked_on' => now()]);
    $entry->timeable()->associate($task);
    $entry->save();

    $entry->update(['minutes' => 180]);

    expect(ultimoCambio($task)['new']['label'])->toBe('180');
});
