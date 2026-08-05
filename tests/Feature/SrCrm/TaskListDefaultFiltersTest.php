<?php

declare(strict_types=1);

use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SrJingles\SrCrm\Enums\TaskStatus;

/*
 | Filtros por defecto del listado de Tareas (addon srjingles/sr-crm):
 |
 |  - "Ocultar completadas y canceladas"
 |  - "Solo mías o sin asignar"
 |
 | Los dos los aporta el addon por el seam `filament.extra_table_filters`: Table::filters()
 | reemplaza la lista y Table::configureUsing() corre antes, así que sin el seam no habría
 | forma de añadirlos.
 |
 | Lo importante del segundo es el "o sin asignar": filtrar solo por las propias dejaría
 | invisible para TODO el equipo la tarea que no es de nadie, que es justo la que se cae por
 | las grietas. El `assigned_to_me` del host sigue ahí, apagado, como versión estricta.
 |
 | Lo que se fija aquí es lo que el usuario ve al abrir la página, y que los filtros se
 | puedan quitar: "por defecto no se ven, pero se pueden ver".
 */

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->personalTeam();
    $this->otro = User::factory()->create();
    $this->team->users()->attach($this->otro, ['role' => 'editor']);

    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

/** Crea una tarea del equipo, con estado y responsable opcionales. */
function tareaCon(?TaskStatus $status = null, ?User $assignee = null): Task
{
    $task = Task::factory()->for(test()->team)->create();

    if ($assignee instanceof User) {
        $task->assignees()->attach($assignee);
    }

    if ($status instanceof TaskStatus) {
        $fieldId = DB::table('custom_fields')
            ->where('tenant_id', test()->team->getKey())
            ->where('entity_type', 'task')
            ->where('code', 'status')
            ->value('id');

        DB::table('custom_field_values')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => test()->team->getKey(),
            'entity_type' => 'task',
            'entity_id' => $task->getKey(),
            'custom_field_id' => $fieldId,
            'string_value' => DB::table('custom_field_options')
                ->where('custom_field_id', $fieldId)
                ->where('name', $status->label())
                ->value('id'),
        ]);
    }

    return $task;
}

it('abre el listado ocultando las completadas y las canceladas', function (): void {
    $viva = tareaCon(TaskStatus::InProgress, $this->owner);
    $completada = tareaCon(TaskStatus::Completed, $this->owner);
    $cancelada = tareaCon(TaskStatus::Cancelled, $this->owner);

    livewire(ManageTasks::class)
        ->assertCanSeeTableRecords([$viva])
        ->assertCanNotSeeTableRecords([$completada, $cancelada]);
});

it('deja ver las cerradas al quitar el filtro', function (): void {
    // La otra mitad del requisito: se ocultan por defecto, pero se pueden ver.
    $viva = tareaCon(TaskStatus::InProgress, $this->owner);
    $completada = tareaCon(TaskStatus::Completed, $this->owner);

    livewire(ManageTasks::class)
        ->set('tableFilters.hide_closed.isActive', false)
        ->assertCanSeeTableRecords([$viva, $completada]);
});

it('abre el listado con mis tareas y las que no son de nadie', function (): void {
    $mia = tareaCon(TaskStatus::InProgress, $this->owner);
    $ajena = tareaCon(TaskStatus::InProgress, $this->otro);
    $sinResponsable = tareaCon(TaskStatus::InProgress);

    livewire(ManageTasks::class)
        ->assertCanSeeTableRecords([$mia, $sinResponsable])
        ->assertCanNotSeeTableRecords([$ajena]);
});

it('no esconde a nadie la tarea sin responsable', function (): void {
    // El punto ciego que se evita: sin el "o sin asignar", una tarea que no es de nadie no
    // aparecería en el listado de NINGÚN miembro del equipo.
    $huerfana = tareaCon(TaskStatus::InProgress);

    // Otro miembro del equipo, con su contexto de panel montado igual que el del owner.
    $this->otro->forceFill(['current_team_id' => $this->team->getKey()])->save();
    $this->actingAs($this->otro);
    Filament::setTenant($this->team);

    livewire(ManageTasks::class)->assertCanSeeTableRecords([$huerfana]);
});

it('deja ver las de los demás al quitar el filtro', function (): void {
    $mia = tareaCon(TaskStatus::InProgress, $this->owner);
    $ajena = tareaCon(TaskStatus::InProgress, $this->otro);

    livewire(ManageTasks::class)
        ->set('tableFilters.mine_or_unassigned.isActive', false)
        ->assertCanSeeTableRecords([$mia, $ajena]);
});

it('mantiene disponible el filtro estricto del host, apagado', function (): void {
    // "Asignadas a mí" no se toca: sigue existiendo para quien quiera excluir también las
    // que no son de nadie, pero no arranca activo.
    $mia = tareaCon(TaskStatus::InProgress, $this->owner);
    $huerfana = tareaCon(TaskStatus::InProgress);

    livewire(ManageTasks::class)
        ->assertCanSeeTableRecords([$mia, $huerfana])
        ->set('tableFilters.assigned_to_me.isActive', true)
        ->assertCanSeeTableRecords([$mia])
        ->assertCanNotSeeTableRecords([$huerfana]);
});

it('no oculta nada si el equipo no tiene sembradas las opciones de estado', function (): void {
    // Degradación segura: más vale enseñar de más que esconder de más.
    DB::table('custom_field_options')
        ->whereIn('custom_field_id', DB::table('custom_fields')
            ->where('tenant_id', $this->team->getKey())
            ->where('entity_type', 'task')
            ->where('code', 'status')
            ->pluck('id'))
        ->delete();

    $tarea = tareaCon(null, $this->owner);

    livewire(ManageTasks::class)->assertCanSeeTableRecords([$tarea]);
});
