<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use SrJingles\SrCrm\Enums\ProjectWorkType;
use SrJingles\SrCrm\Models\Project;
use SrJingles\SrCrm\Models\ProjectHourClosing;
use SrJingles\SrCrm\Models\TimeEntry;
use SrJingles\SrCrm\Support\HourClosingCalculator;
use SrJingles\SrCrm\Support\ProjectHourAccount;

/*
 | Cierres mensuales del cupo de horas: la trayectoria real del proyecto.
 |
 | Son FOTOS, no contabilidad. El saldo en vivo siempre refleja la realidad; el cierre
 | guarda lo que se veía ese día, y si después entra tiempo de ese mes queda DESFASADO y se
 | regenera a mano.
 |
 | Y resuelven un problema que el cálculo en vivo no puede resolver solo: `monthly_hours` no
 | tiene historia, así que sin cierres renegociar el fee reescribiría los meses ya pasados.
 */

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->personalTeam();
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
});

function campoDeProyecto(string $code): CustomField
{
    return CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', test()->team->getKey())
        ->where('entity_type', 'project')
        ->where('code', $code)
        ->firstOrFail();
}

function eligeOpcion(Project $project, string $code, string $label): void
{
    $field = campoDeProyecto($code);

    $project->saveCustomFieldValue($field, (string) $field->options()
        ->withoutGlobalScopes()
        ->where('name', $label)
        ->firstOrFail()
        ->getKey());
}

function imputa(Project $project, int $minutes, string $workedOn): void
{
    $task = Task::factory()->for(test()->team)->create();
    $project->tasks()->attach($task);

    $entry = new TimeEntry([
        'user_id' => test()->owner->getKey(),
        'minutes' => $minutes,
        'worked_on' => $workedOn,
    ]);
    $entry->timeable()->associate($task);
    $entry->save();
}

/** Proyecto de fee mensual con su contrato puesto. */
function feeMensual(int $minutosPorMes = 2400, string $desde = '2026-01-01'): Project
{
    $project = Project::query()->create(['name' => 'Fee RRSS']);
    eligeOpcion($project, 'work_type', ProjectWorkType::MonthlyHours->label());
    $project->saveCustomFieldValue(campoDeProyecto('monthly_hours'), $minutosPorMes);
    $project->saveCustomFieldValue(campoDeProyecto('start_date'), $desde);

    return $project->refresh();
}

it('guarda la foto del mes con concedido, consumido y saldo', function (): void {
    $project = feeMensual();          // 40 h/mes desde enero
    imputa($project, 2160, '2026-01-20'); // 36 h en enero

    $this->artisan('sr-crm:close-hour-periods', ['--month' => '2026-01'])->assertSuccessful();

    $cierre = ProjectHourClosing::query()->withoutGlobalScopes()->firstOrFail();

    expect($cierre->allocated_minutes)->toBe(2400)
        ->and($cierre->consumed_minutes)->toBe(2160)
        // Saldo al cierre de enero: 40 − 36 = 4 h.
        ->and($cierre->balance_minutes)->toBe(240)
        ->and($cierre->period_start->toDateString())->toBe('2026-01-01')
        ->and($cierre->period_end->toDateString())->toBe('2026-01-31');
});

it('es idempotente: correrlo dos veces no duplica', function (): void {
    $project = feeMensual();
    imputa($project, 600, '2026-01-10');

    $this->artisan('sr-crm:close-hour-periods', ['--month' => '2026-01'])->assertSuccessful();
    $this->artisan('sr-crm:close-hour-periods', ['--month' => '2026-01'])->assertSuccessful();

    expect(ProjectHourClosing::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('ignora los proyectos que no consumen cupo', function (): void {
    $project = Project::query()->create(['name' => 'Web corporativa']);
    eligeOpcion($project, 'work_type', ProjectWorkType::SingleProject->label());
    imputa($project, 600, '2026-01-10');

    $this->artisan('sr-crm:close-hour-periods', ['--month' => '2026-01'])->assertSuccessful();

    expect(ProjectHourClosing::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('marca el cierre como desfasado si entra tiempo de ese mes después', function (): void {
    $project = feeMensual();
    imputa($project, 600, '2026-01-10');

    $this->artisan('sr-crm:close-hour-periods', ['--month' => '2026-01'])->assertSuccessful();

    $cierre = ProjectHourClosing::query()->withoutGlobalScopes()->firstOrFail();
    $calculator = new HourClosingCalculator;

    expect($calculator->isStale($cierre))->toBeFalse();

    // Alguien apunta el viernes lo del martes anterior, ya cerrado.
    imputa($project, 120, '2026-01-28');

    expect($calculator->isStale($cierre->refresh()))->toBeTrue();
});

it('deja de estar desfasado al regenerarlo', function (): void {
    $project = feeMensual();
    imputa($project, 600, '2026-01-10');
    $this->artisan('sr-crm:close-hour-periods', ['--month' => '2026-01'])->assertSuccessful();

    imputa($project, 120, '2026-01-28');

    $this->artisan('sr-crm:close-hour-periods', ['--month' => '2026-01', '--force' => true])->assertSuccessful();

    $cierre = ProjectHourClosing::query()->withoutGlobalScopes()->firstOrFail();

    expect($cierre->consumed_minutes)->toBe(720)
        ->and($cierre->regenerated_at)->not->toBeNull()
        ->and((new HourClosingCalculator)->isStale($cierre))->toBeFalse()
        // Sigue habiendo UN solo cierre de enero.
        ->and(ProjectHourClosing::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('renegociar el fee NO reescribe los meses ya cerrados', function (): void {
    // Es la razón de ser de los cierres. Enero a 40 h, se cierra; en febrero se sube a 50.
    // Sin cierres, el cupo del trimestre se calcularía como 50 × 2 = 100 h, cuando enero
    // fueron 40, y el saldo saldría inflado en 10 h sin que nadie se entere.
    $project = feeMensual(2400, '2026-01-01');
    imputa($project, 2160, '2026-01-20');

    $this->artisan('sr-crm:close-hour-periods', ['--month' => '2026-01'])->assertSuccessful();

    $project->saveCustomFieldValue(campoDeProyecto('monthly_hours'), 3000); // 50 h/mes

    $cuenta = ProjectHourAccount::for($project->refresh(), CarbonImmutable::parse('2026-02-15'));

    // Enero congelado en 40 h + febrero abierto a 50 h = 90 h, no 100.
    expect($cuenta->balance->allocatedMinutes)->toBe(5400);
});

it('sin cierres, renegociar sí recalcula el trimestre en curso', function (): void {
    // La otra cara: mientras el mes está abierto, manda el contrato de hoy. Es el
    // comportamiento correcto y el motivo de cerrar mensualmente.
    $project = feeMensual(2400, '2026-01-01');

    $project->saveCustomFieldValue(campoDeProyecto('monthly_hours'), 3000);

    $cuenta = ProjectHourAccount::for($project->refresh(), CarbonImmutable::parse('2026-02-15'));

    expect($cuenta->balance->allocatedMinutes)->toBe(6000); // 50 × 2
});

it('no concede cupo de los meses anteriores al contrato', function (): void {
    $project = feeMensual(2400, '2026-02-15'); // arranca a mitad de febrero

    $this->artisan('sr-crm:close-hour-periods', ['--month' => '2026-01'])->assertSuccessful();

    $cierre = ProjectHourClosing::query()->withoutGlobalScopes()->firstOrFail();

    // Enero es anterior al contrato: 0 concedidas. Febrero contaría entero.
    expect($cierre->allocated_minutes)->toBe(0);
});

it('no concede cupo mensual a una bolsa de horas', function (): void {
    // El cupo de una bolsa se compra una vez: atribuirle horas a un mes concreto sería
    // inventárselas. Lo que sí se fotografía es el consumo y el saldo.
    $project = Project::query()->create(['name' => 'Mantenimiento web']);
    eligeOpcion($project, 'work_type', ProjectWorkType::HourBank->label());
    $project->saveCustomFieldValue(campoDeProyecto('contracted_hours'), 1200);
    $project->saveCustomFieldValue(campoDeProyecto('start_date'), '2026-01-01');
    imputa($project, 300, '2026-01-15');

    $this->artisan('sr-crm:close-hour-periods', ['--month' => '2026-01'])->assertSuccessful();

    $cierre = ProjectHourClosing::query()->withoutGlobalScopes()->firstOrFail();

    expect($cierre->allocated_minutes)->toBe(0)
        ->and($cierre->consumed_minutes)->toBe(300)
        ->and($cierre->balance_minutes)->toBe(900);
});

it('rechaza un mes con formato inválido', function (): void {
    $this->artisan('sr-crm:close-hour-periods', ['--month' => 'julio'])->assertFailed();
});

it('hereda el equipo del proyecto aunque no haya tenant activo', function (): void {
    // El comando corre en consola, sin contexto de tenant: sin team_id el cierre sería
    // invisible para el scope de Filament.
    $project = feeMensual();
    imputa($project, 600, '2026-01-10');

    Filament::setTenant(null);

    $this->artisan('sr-crm:close-hour-periods', ['--month' => '2026-01'])->assertSuccessful();

    $cierre = ProjectHourClosing::query()->withoutGlobalScopes()->firstOrFail();

    expect($cierre->team_id)->toBe($this->team->getKey());
});
