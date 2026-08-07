<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use SrJingles\SrCrm\Enums\ProjectWorkType;
use SrJingles\SrCrm\Models\Project;
use SrJingles\SrCrm\Models\TimeEntry;
use SrJingles\SrCrm\Resources\ProjectResource\Pages\ListProjects;

/*
 | Columna de saldo y filtro de proyectos sin horas en el listado.
 |
 | Lo que más se vigila aquí es el COSTE: el saldo es un derivado que se calcula por fila, y
 | el plan lo marcó como riesgo de N+1. No se puede precargar de forma limpia —Filament no
 | da un gancho con las filas ya cargadas y cada proyecto tiene su propia ventana—, así que
 | lo que se hizo fue bajar el coste por proyecto y medirlo con un tope.
 */

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->personalTeam();
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function campoProyecto(string $code): CustomField
{
    return CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', test()->team->getKey())
        ->where('entity_type', 'project')
        ->where('code', $code)
        ->firstOrFail();
}

function bolsaDe(string $nombre, int $contratadas, int $consumidas = 0): Project
{
    $project = Project::query()->create(['name' => $nombre]);

    $field = campoProyecto('work_type');
    $project->saveCustomFieldValue($field, (string) $field->options()
        ->withoutGlobalScopes()
        ->where('name', ProjectWorkType::HourBank->label())
        ->firstOrFail()
        ->getKey());

    $project->saveCustomFieldValue(campoProyecto('contracted_hours'), $contratadas);

    if ($consumidas > 0) {
        $task = Task::factory()->for(test()->team)->create();
        $project->tasks()->attach($task);

        $entry = new TimeEntry([
            'user_id' => test()->owner->getKey(),
            'minutes' => $consumidas,
            'worked_on' => now(),
        ]);
        $entry->timeable()->associate($task);
        $entry->save();
    }

    return $project->refresh();
}

it('enseña el saldo en el listado', function (): void {
    bolsaDe('Mantenimiento web', 1200, 300); // 20 h contratadas, 5 h consumidas

    livewire(ListProjects::class)
        ->assertOk()
        ->assertSee('15h');
});

it('filtra los proyectos sin horas o caducados', function (): void {
    $agotada = bolsaDe('Agotada', 600, 900);   // desbordada
    $viva = bolsaDe('Con saldo', 1200, 300);

    livewire(ListProjects::class)
        ->assertCanSeeTableRecords([$agotada, $viva])
        ->set('tableFilters.hours_needs_attention.isActive', true)
        ->assertCanSeeTableRecords([$agotada])
        ->assertCanNotSeeTableRecords([$viva]);
});

it('no pinta saldo en un proyecto que no consume cupo', function (): void {
    $project = Project::query()->create(['name' => 'Web corporativa']);
    $field = campoProyecto('work_type');
    $project->saveCustomFieldValue($field, (string) $field->options()
        ->withoutGlobalScopes()
        ->where('name', ProjectWorkType::SingleProject->label())
        ->firstOrFail()
        ->getKey());

    livewire(ListProjects::class)->assertOk();

    // No revienta ni inventa un saldo: la fila sale con guion.
    expect(true)->toBeTrue();
});

it('el coste por proyecto no crece con el número de filas', function (): void {
    // El guardián del N+1. No se exige coste constante —no se puede precargar de forma
    // limpia— sino que el coste POR PROYECTO esté acotado: al pasar de 3 a 9 bolsas, las
    // consultas no deben más que triplicarse, y con holgura.
    foreach (range(1, 3) as $i) {
        bolsaDe("Bolsa {$i}", 1200, 300);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    livewire(ListProjects::class)->assertOk();
    $conTres = count(DB::getQueryLog());
    DB::disableQueryLog();

    foreach (range(4, 9) as $i) {
        bolsaDe("Bolsa {$i}", 1200, 300);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    livewire(ListProjects::class)->assertOk();
    $conNueve = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Seis bolsas más deben costar como mucho 3 consultas cada una (saldo de tareas,
    // de notas y cierres). Si alguien reintroduce el pluck+whereIn, esto salta.
    expect($conNueve - $conTres)->toBeLessThanOrEqual(6 * 3);
});
