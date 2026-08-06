<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\User;
use Filament\Facades\Filament;
use SrJingles\SrCrm\Models\Project;
use SrJingles\SrCrm\Resources\ProjectResource\Pages\ViewProject;
use SrJingles\SrCrm\Support\EffortAnalysisVisibility;

/*
 | El ANÁLISIS de esfuerzo (estimado agregado, registrado agregado y desviación) solo lo ven
 | los roles configurados; el tiempo EN BRUTO lo sigue viendo todo el equipo.
 |
 | La distinción es la política entera: hay que poder imputar las propias horas y saber el
 | presupuesto de la tarea que uno ejecuta. Lo que se restringe es la lectura de negocio.
 |
 | Se apoya en los roles de equipo que el host YA declara (Jetstream admin/editor); no hay
 | sistema de permisos nuevo.
 */

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->personalTeam();

    $this->editor = User::factory()->create();
    $this->team->users()->attach($this->editor, ['role' => TeamRole::Editor->value]);

    $this->admin = User::factory()->create();
    $this->team->users()->attach($this->admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

/** Pone a $user como usuario activo del panel, con su contexto montado. */
function actuandoComo(User $user): void
{
    $user->forceFill(['current_team_id' => test()->team->getKey()])->save();
    test()->actingAs($user);
    Filament::setTenant(test()->team);
}

it('deja ver el análisis al dueño del equipo', function (): void {
    expect(EffortAnalysisVisibility::allowed())->toBeTrue();
});

it('deja ver el análisis a un administrador del equipo', function (): void {
    actuandoComo($this->admin);

    expect(EffortAnalysisVisibility::allowed())->toBeTrue();
});

it('se lo oculta a un editor', function (): void {
    actuandoComo($this->editor);

    expect(EffortAnalysisVisibility::allowed())->toBeFalse();
});

it('no enseña el bloque de esfuerzo en la ficha del proyecto a un editor', function (): void {
    $project = Project::query()->create(['name' => 'Web corporativa']);

    actuandoComo($this->editor);

    livewire(ViewProject::class, ['record' => $project->getKey()])
        ->assertOk()
        ->assertDontSee(__('filament/resources/project.fields.estimate_deviation.label'))
        ->assertDontSee(__('filament/resources/project.fields.estimated_effort.label'));
});

it('sí lo enseña al dueño', function (): void {
    $project = Project::query()->create(['name' => 'Web corporativa']);

    livewire(ViewProject::class, ['record' => $project->getKey()])
        ->assertOk()
        ->assertSee(__('filament/resources/project.fields.estimate_deviation.label'));
});

it('vuelve a enseñárselo a todos si se desactiva la restricción', function (): void {
    config()->set('srcrm.effort_analysis.restrict', false);

    actuandoComo($this->editor);

    expect(EffortAnalysisVisibility::allowed())->toBeTrue();
});

it('deniega si no hay nadie autenticado', function (): void {
    auth()->logout();

    expect(EffortAnalysisVisibility::allowed())->toBeFalse();
});
