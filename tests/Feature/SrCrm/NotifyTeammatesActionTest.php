<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use SrJingles\SrCrm\Filament\Actions\NotifyTeammatesAction;
use SrJingles\SrCrm\Models\Project;
use SrJingles\SrCrm\Notifications\RecordMentionNotifier;

/*
 | Acción explícita "Avisar a un compañero" (addon srjingles/sr-crm): inyectada en las
 | tablas de Task/Note del host (y del ProjectResource propio) vía
 | ActionGroup::configureUsing, notifica por la campana a los miembros elegidos. Se cubre
 | el notificador (envío database), el gating de supports() y la inyección/aislamiento en
 | la tabla real.
 */

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->personalTeam();
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);

    $this->member = User::factory()->create();
    $this->team->users()->attach($this->member, ['role' => 'editor']);
});

it('el notificador avisa por database a los miembros indicados', function (): void {
    $task = Task::factory()->for($this->team)->create(['title' => 'Diseñar landing']);

    app(RecordMentionNotifier::class)->notify($task, [$this->member->getKey()], $this->owner, 'échale un ojo', 'notify');

    expect($this->member->notifications()->count())->toBe(1);

    $data = $this->member->notifications()->first()->data;
    expect($data['viewData']['reason'])->toBe('notify')
        ->and($data['viewData']['record_id'])->toBe((string) $task->getKey())
        ->and($data['viewData']['message'])->toBe('échale un ojo');

    // El botón "Ver" abre la tarea (monta su modal edit vía tableAction).
    expect($data['actions'][0]['url'])
        ->toContain('tableAction=edit')
        ->toContain((string) $task->getKey());
});

it('nunca se autonotifica al actor aunque se incluya en la lista', function (): void {
    $task = Task::factory()->for($this->team)->create(['title' => 'X']);

    app(RecordMentionNotifier::class)->notify(
        $task,
        [$this->owner->getKey(), $this->member->getKey()],
        $this->owner,
        null,
        'notify',
    );

    expect($this->owner->notifications()->count())->toBe(0)
        ->and($this->member->notifications()->count())->toBe(1);
});

it('supports() solo habilita la acción en Tarea, Proyecto y Nota', function (): void {
    expect(NotifyTeammatesAction::supports(new Task))->toBeTrue()
        ->and(NotifyTeammatesAction::supports(new Note))->toBeTrue()
        ->and(NotifyTeammatesAction::supports(new Project))->toBeTrue()
        ->and(NotifyTeammatesAction::supports(new Company))->toBeFalse()
        ->and(NotifyTeammatesAction::supports(null))->toBeFalse();
});

it('inyecta la acción, visible, en la tabla de Tareas del host', function (): void {
    $task = Task::factory()->for($this->team)->create(['title' => 'X']);

    Livewire::test(\App\Filament\Resources\TaskResource\Pages\ManageTasks::class)
        ->assertTableActionVisible('srcrm_notify_teammates', $task);
});

it('mantiene la acción oculta en un recurso no soportado (Company)', function (): void {
    $company = Company::factory()->for($this->team)->create();

    Livewire::test(\App\Filament\Resources\CompanyResource\Pages\ListCompanies::class)
        ->assertTableActionHidden('srcrm_notify_teammates', $company);
});

it('al ejecutar la acción notifica al miembro seleccionado', function (): void {
    $task = Task::factory()->for($this->team)->create(['title' => 'X']);

    Livewire::test(\App\Filament\Resources\TaskResource\Pages\ManageTasks::class)
        ->callTableAction('srcrm_notify_teammates', $task, ['user_ids' => [(string) $this->member->getKey()]])
        ->assertHasNoTableActionErrors();

    expect($this->member->notifications()->count())->toBe(1);
});
