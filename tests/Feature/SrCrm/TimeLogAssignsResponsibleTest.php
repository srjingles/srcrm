<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use SrJingles\SrCrm\Models\TimeEntry;

/*
 | Quien tiene tiempo imputado en una tarea pasa a ser responsable de ella
 | (TimeEntryObserver, addon srjingles/sr-crm).
 |
 | Va de la mano del filtro por defecto del listado: si alguien ha trabajado en la tarea
 | pero no consta como responsable, el listado —que por defecto muestra solo las propias—
 | le escondería justo la tarea en la que está metido.
 */

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->personalTeam();
    $this->companero = User::factory()->create();
    $this->team->users()->attach($this->companero, ['role' => 'editor']);

    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
});

/** Imputa minutos a la tarea en nombre de un miembro. */
function imputar(Task $task, User $user, int $minutes = 30): TimeEntry
{
    $entry = new TimeEntry(['user_id' => $user->getKey(), 'minutes' => $minutes, 'worked_on' => now()]);
    $entry->timeable()->associate($task);
    $entry->save();

    return $entry;
}

it('añade como responsable a quien registra tiempo', function (): void {
    $task = Task::factory()->for($this->team)->create();

    imputar($task, $this->companero);

    expect($task->assignees()->pluck('users.id')->all())
        ->toBe([(string) $this->companero->getKey()]);
});

it('no duplica al responsable que ya lo era', function (): void {
    $task = Task::factory()->for($this->team)->create();
    $task->assignees()->attach($this->companero);

    imputar($task, $this->companero);
    imputar($task, $this->companero);

    expect($task->assignees()->count())->toBe(1);
});

it('añade también al reasignar una entrada existente a otro miembro', function (): void {
    $task = Task::factory()->for($this->team)->create();
    $entry = imputar($task, $this->owner);

    $entry->update(['user_id' => $this->companero->getKey()]);

    // Se añade el nuevo y NO se quita el anterior: quitar por debajo sería destructivo.
    expect($task->assignees()->pluck('users.id')->all())
        ->toContain((string) $this->owner->getKey())
        ->toContain((string) $this->companero->getKey());
});

it('no quita a nadie al borrar el tiempo', function (): void {
    $task = Task::factory()->for($this->team)->create();
    $entry = imputar($task, $this->companero);

    $entry->delete();

    expect($task->assignees()->pluck('users.id')->all())
        ->toBe([(string) $this->companero->getKey()]);
});

it('respeta el interruptor de configuración', function (): void {
    config()->set('srcrm.time_log.assign_on_log', false);

    $task = Task::factory()->for($this->team)->create();
    imputar($task, $this->companero);

    expect($task->assignees()->count())->toBe(0);
});
