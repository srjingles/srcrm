<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use SrJingles\SrCrm\Resources\ProjectResource\Pages\ListProjects;

/*
 | Integración del addon srjingles/sr-crm: el listado de Proyectos.
 |
 | Filament compone la tabla en DOS pasos que suman: Resource::configureTable()
 | aplica ProjectResource::table(), y después se aplica el table() de la propia
 | página. Si ambos añaden las columnas de campos personalizados, cada una entra
 | dos veces — y como el gestor de columnas indexa el estado por nombre
 | (HasColumnManager::getTableColumnsToggleStateByName()), las dos casillas
 | duplicadas se marcan y desmarcan a la vez.
 */

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('no repite ninguna columna en el gestor de columnas', function (): void {
    $names = collect(livewire(ListProjects::class)->get('tableColumns'))
        ->pluck('name')
        ->all();

    $duplicated = collect($names)
        ->duplicates()
        ->unique()
        ->values()
        ->all();

    expect($duplicated)->toBe([]);
});

it('conserva los filtros de campos personalizados al quitar el trait', function (): void {
    // Se movieron a ProjectResource::table() con pushFilters(): InteractsWithCustomFields
    // los aportaba y ya no está en la página. Sin esto, el listado se quedaría solo con
    // TrashedFilter y nadie lo notaría hasta echarlos de menos en producción.
    $filters = array_keys(livewire(ListProjects::class)->instance()->getTable()->getFilters());

    expect($filters)->toContain('trashed')
        ->and(collect($filters)->filter(fn (string $name): bool => str_starts_with($name, 'custom_fields.')))
        ->not->toBeEmpty();
});

it('mantiene las columnas de campos personalizados entre la empresa y el creador', function (): void {
    $names = collect(livewire(ListProjects::class)->get('tableColumns'))
        ->pluck('name')
        ->all();

    // El orden es deliberado en ProjectResource::table(): los campos personalizados
    // (work_type, status...) van tras la empresa, no arrastrados al final.
    expect(array_search('company.name', $names, true))
        ->toBeLessThan(array_search('creator.name', $names, true));

    $customFieldPositions = collect($names)
        ->keys()
        ->filter(fn (int $index): bool => str_starts_with($names[$index], 'custom_fields.'))
        ->values();

    expect($customFieldPositions)->not->toBeEmpty();

    expect($customFieldPositions->min())->toBeGreaterThan(array_search('company.name', $names, true));
    expect($customFieldPositions->max())->toBeLessThan(array_search('creator.name', $names, true));
});
