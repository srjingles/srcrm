<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use SrJingles\SrCrm\Models\ProjectTemplate;
use SrJingles\SrCrm\Resources\ProjectTemplateResource;
use SrJingles\SrCrm\Resources\ProjectTemplateResource\Pages\ListProjectTemplates;

/*
 | El alta de Plantillas de Proyecto se hace en MODAL, como el resto de recursos del
 | sistema. El mecanismo es que el recurso ya no registra página 'create': sin ella,
 | Filament resuelve la CreateAction de la cabecera contra un modal en vez de navegar.
 |
 | La página 'edit' SÍ sigue existiendo, y no es un olvido: hospeda el relation manager con
 | el checklist de tareas de la plantilla, que no cabe en un modal. Por eso el alta redirige
 | ahí en cuanto se crea.
 */

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->personalTeam();
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

it('no registra página de creación, así que el alta cae al modal', function (): void {
    $pages = ProjectTemplateResource::getPages();

    expect($pages)->toHaveKeys(['index', 'edit'])
        ->and($pages)->not->toHaveKey('create');
});

it('crea la plantilla desde el modal del listado', function (): void {
    livewire(ListProjectTemplates::class)
        ->callAction('create', data: [
            'name' => 'Web corporativa',
            'work_type' => ProjectTemplate::workTypes()[0],
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $template = ProjectTemplate::query()->withoutGlobalScopes()->firstWhere('name', 'Web corporativa');

    expect($template)->not->toBeNull()
        ->and($template->team_id)->toBe($this->team->getKey())
        ->and((bool) $template->is_active)->toBeTrue();
});

it('lleva a la edición tras crearla, que es donde se añaden las tareas', function (): void {
    // Una plantilla sin tareas no sirve para nada: dejar al usuario en el listado sería
    // dejarlo a medias.
    $componente = livewire(ListProjectTemplates::class)
        ->callAction('create', data: [
            'name' => 'Fee mensual RRSS',
            'work_type' => ProjectTemplate::workTypes()[0],
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $template = ProjectTemplate::query()->withoutGlobalScopes()->firstWhere('name', 'Fee mensual RRSS');

    $componente->assertRedirect(ProjectTemplateResource::getUrl('edit', ['record' => $template]));
});

it('mantiene la edición como página, con su checklist de tareas', function (): void {
    // El modelo del addon no tiene factory (es de equipo, se crea por el panel/Actions).
    $template = ProjectTemplate::query()->create(['name' => 'Plantilla', 'is_active' => true]);

    $this->get(ProjectTemplateResource::getUrl('edit', ['record' => $template]))
        ->assertOk();
});
