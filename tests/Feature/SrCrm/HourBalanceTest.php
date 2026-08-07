<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use SrJingles\SrCrm\Enums\ProjectWorkType;
use SrJingles\SrCrm\Models\Project;
use SrJingles\SrCrm\Models\TimeEntry;
use SrJingles\SrCrm\Resources\ProjectResource\Pages\ViewProject;
use SrJingles\SrCrm\Support\ProjectHourPlan;
use SrJingles\SrCrm\Support\ProjectTimeAggregator;

/*
 | Saldo de horas contra BD (addon srjingles/sr-crm).
 |
 | La aritmética y el calendario están cubiertos en la suite unit del addon (HourPeriodTest,
 | HourBalanceTest). Lo que se fija aquí es lo que aquella no puede ver: que la ventana
 | acote de verdad por `worked_on`, que el plan se lea bien de los custom fields y que las
 | dos caducidades se comporten distinto.
 */

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->personalTeam();
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
});

/** Custom field de Proyecto por code. */
function projectField(string $code): CustomField
{
    return CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', test()->team->getKey())
        ->where('entity_type', 'project')
        ->where('code', $code)
        ->firstOrFail();
}

/** Fija el valor de un campo de elección por la etiqueta de su opción. */
function setChoice(Project $project, string $code, string $label): void
{
    $field = projectField($code);

    $project->saveCustomFieldValue($field, (string) $field->options()
        ->withoutGlobalScopes()
        ->where('name', $label)
        ->firstOrFail()
        ->getKey());
}

/** Imputa minutos al proyecto a través de una tarea suya, con fecha. */
function imputarAlProyecto(Project $project, int $minutes, string $workedOn): void
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

it('no devuelve plan para un tipo que no consume cupo', function (): void {
    $project = Project::query()->create(['name' => 'Web corporativa']);
    setChoice($project, 'work_type', ProjectWorkType::SingleProject->label());

    expect(ProjectHourPlan::forProject($project->refresh()))->toBeNull();
});

it('lee el contrato de una bolsa de horas', function (): void {
    $project = Project::query()->create(['name' => 'Mantenimiento web']);
    setChoice($project, 'work_type', ProjectWorkType::HourBank->label());
    $project->saveCustomFieldValue(projectField('contracted_hours'), 1200); // 20 h
    $project->saveCustomFieldValue(projectField('start_date'), '2026-01-15');

    $plan = ProjectHourPlan::forProject($project->refresh());

    expect($plan)->not->toBeNull()
        ->and($plan->type)->toBe(ProjectWorkType::HourBank)
        ->and($plan->contractedMinutes)->toBe(1200)
        ->and($plan->allocatedMinutes())->toBe(1200)
        ->and($plan->startDate->toDateString())->toBe('2026-01-15');
});

it('devenga el cupo mensual por meses transcurridos del trimestre', function (): void {
    $project = Project::query()->create(['name' => 'Fee RRSS']);
    setChoice($project, 'work_type', ProjectWorkType::MonthlyHours->label());
    $project->saveCustomFieldValue(projectField('monthly_hours'), 2400); // 40 h/mes
    $project->saveCustomFieldValue(projectField('start_date'), '2026-01-01');

    $plan = ProjectHourPlan::forProject($project->refresh());

    // A mediados de febrero van dos meses devengados del trimestre: 80 h.
    expect($plan->allocatedMinutes(CarbonImmutable::parse('2026-02-17')))->toBe(4800)
        // El 1 de abril arranca trimestre nuevo: vuelve a un mes.
        ->and($plan->allocatedMinutes(CarbonImmutable::parse('2026-04-01')))->toBe(2400);
});

it('acota el consumo a la ventana por la fecha del apunte', function (): void {
    $project = Project::query()->create(['name' => 'Fee RRSS']);

    imputarAlProyecto($project, 120, '2026-01-20');  // dentro del Q1
    imputarAlProyecto($project, 60, '2026-03-05');   // dentro del Q1
    imputarAlProyecto($project, 999, '2025-12-28');  // trimestre anterior
    imputarAlProyecto($project, 555, '2026-04-02');  // trimestre siguiente

    $consumido = (new ProjectTimeAggregator)->minutesBetween(
        $project,
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    expect($consumido)->toBe(180);
});

it('incluye el último día de la ventana', function (): void {
    // `worked_on` es una columna date: comparar con datetimes dejaría fuera el último día.
    $project = Project::query()->create(['name' => 'Fee RRSS']);
    imputarAlProyecto($project, 90, '2026-03-31');

    $consumido = (new ProjectTimeAggregator)->minutesBetween(
        $project,
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    expect($consumido)->toBe(90);
});

it('cuenta el trabajo hecho después de caducar una bolsa', function (): void {
    // Caducar solo AVISA: si se sigue trabajando, ese tiempo tiene que verse en el saldo
    // (que se va a negativo), no esconderse recortando la ventana.
    $project = Project::query()->create(['name' => 'Mantenimiento web']);
    setChoice($project, 'work_type', ProjectWorkType::HourBank->label());
    $project->saveCustomFieldValue(projectField('contracted_hours'), 600); // 10 h
    $project->saveCustomFieldValue(projectField('start_date'), '2026-01-01');
    $project->saveCustomFieldValue(projectField('hours_expires_on'), '2026-02-28');

    imputarAlProyecto($project, 480, '2026-02-10');  // antes de caducar
    imputarAlProyecto($project, 300, '2026-03-15');  // DESPUÉS de caducar

    $plan = ProjectHourPlan::forProject($project->refresh());
    $period = $plan->period();

    $consumido = (new ProjectTimeAggregator)->minutesBetween($project, $period->start, $period->end);

    expect($consumido)->toBe(780)
        ->and($plan->hasExpired(CarbonImmutable::parse('2026-03-20')))->toBeTrue()
        // El cupo NO se pone a cero al caducar.
        ->and($plan->allocatedMinutes())->toBe(600);
});

it('la duración negociada propone la fecha de caducidad', function (): void {
    $project = Project::query()->create(['name' => 'Mantenimiento web']);
    setChoice($project, 'work_type', ProjectWorkType::HourBank->label());
    $project->saveCustomFieldValue(projectField('start_date'), '2026-01-15');

    $project->saveCustomFieldValue(projectField('hours_duration_months'), 6);

    $plan = ProjectHourPlan::forProject($project->refresh());

    expect($plan->expiresOn->toDateString())->toBe('2026-07-15');
});

it('editar la fecha a mano no toca la duración', function (): void {
    // La regla en una frase: cambiar los meses recalcula la fecha; editar la fecha no
    // recalcula los meses. La fecha es la que manda.
    $project = Project::query()->create(['name' => 'Mantenimiento web']);
    setChoice($project, 'work_type', ProjectWorkType::HourBank->label());
    $project->saveCustomFieldValue(projectField('start_date'), '2026-01-15');
    $project->saveCustomFieldValue(projectField('hours_duration_months'), 6);

    $project->saveCustomFieldValue(projectField('hours_expires_on'), '2026-12-31');

    $project->refresh();
    $plan = ProjectHourPlan::forProject($project);

    expect($plan->expiresOn->toDateString())->toBe('2026-12-31');

    $meses = $project->customFieldValues()
        ->withoutGlobalScopes()
        ->where('custom_field_id', projectField('hours_duration_months')->getKey())
        ->first();

    expect((int) $meses->getValue())->toBe(6);
});

it('respeta el interruptor de la caducidad automática', function (): void {
    config()->set('srcrm.hours.sync_expiry', false);

    $project = Project::query()->create(['name' => 'Mantenimiento web']);
    setChoice($project, 'work_type', ProjectWorkType::HourBank->label());
    $project->saveCustomFieldValue(projectField('start_date'), '2026-01-15');
    $project->saveCustomFieldValue(projectField('hours_duration_months'), 6);

    expect(ProjectHourPlan::forProject($project->refresh())->expiresOn)->toBeNull();
});

it('enseña el bloque de saldo al dueño y se lo oculta a un editor', function (): void {
    // Un saldo que se agota es la misma señal comercial que una desviación, así que va con
    // el mismo criterio de visibilidad (EffortAnalysisVisibility).
    $editor = User::factory()->create();
    $this->team->users()->attach($editor, ['role' => 'editor']);

    $project = Project::query()->create(['name' => 'Mantenimiento web']);
    setChoice($project, 'work_type', ProjectWorkType::HourBank->label());
    $project->saveCustomFieldValue(projectField('contracted_hours'), 1200);

    Filament::setCurrentPanel(Filament::getPanel('app'));

    livewire(ViewProject::class, ['record' => $project->getKey()])
        ->assertOk()
        ->assertSee(__('filament/resources/project.fields.hours_remaining.label'));

    $editor->forceFill(['current_team_id' => $this->team->getKey()])->save();
    $this->actingAs($editor);
    Filament::setTenant($this->team);

    livewire(ViewProject::class, ['record' => $project->getKey()])
        ->assertOk()
        ->assertDontSee(__('filament/resources/project.fields.hours_remaining.label'));
});

it('no enseña el bloque en un proyecto que no consume cupo', function (): void {
    $project = Project::query()->create(['name' => 'Web corporativa']);
    setChoice($project, 'work_type', ProjectWorkType::SingleProject->label());

    Filament::setCurrentPanel(Filament::getPanel('app'));

    livewire(ViewProject::class, ['record' => $project->getKey()])
        ->assertOk()
        ->assertDontSee(__('filament/resources/project.fields.hours_remaining.label'));
});
