<?php

declare(strict_types=1);

use App\Actions\User\SaveUserPreference;
use App\Filament\Concerns\PersistsTableColumns;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\CompanyResource\RelationManagers\NotesRelationManager;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Models\Company;
use App\Models\Note;
use App\Models\User;
use App\Models\UserPreference;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

mutates(PersistsTableColumns::class, SaveUserPreference::class, UserPreference::class);

/*
 | La configuración de columnas visibles de cada listado es de cada usuario y sobrevive
 | a la sesión. Filament ya la guardaba, pero en la sesión; aquí solo se comprueba el
 | almacén nuevo, siempre a través del componente Livewire real.
 |
 | `session()->flush()` entre dos instancias del componente es lo que da valor al test:
 | si la persistencia volviera a depender de la sesión, esa línea lo delataría.
 */

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

/**
 * @param  array<int, array<string, mixed>>  $state
 * @return array<int, array<string, mixed>>
 */
function withColumnToggledOff(array $state, string $name): array
{
    foreach ($state as $index => $item) {
        if (($item['name'] ?? null) === $name) {
            $state[$index]['isToggled'] = false;
        }
    }

    return $state;
}

it('guarda la elección de columnas del usuario, acotada a su equipo', function (): void {
    $component = livewire(ListCompanies::class);

    $component->call(
        'applyTableColumnManager',
        withColumnToggledOff($component->get('tableColumns'), 'created_at'),
    );

    $preference = UserPreference::query()
        ->where('user_id', $this->user->getKey())
        ->where('team_id', $this->team->getKey())
        ->where('key', 'table_columns:'.ListCompanies::class)
        ->first();

    expect($preference)->not->toBeNull();

    $stored = collect($preference->value)->firstWhere('name', 'created_at');

    expect($stored['isToggled'])->toBeFalse();
});

it('mantiene la elección en una sesión nueva', function (): void {
    $component = livewire(ListCompanies::class);

    $component->call(
        'applyTableColumnManager',
        withColumnToggledOff($component->get('tableColumns'), 'created_at'),
    );

    session()->flush();

    livewire(ListCompanies::class)
        ->assertCanNotRenderTableColumn('created_at')
        ->assertCanRenderTableColumn('updated_at');
});

it('no arrastra la elección de un usuario a otro del mismo equipo', function (): void {
    $component = livewire(ListCompanies::class);

    $component->call(
        'applyTableColumnManager',
        withColumnToggledOff($component->get('tableColumns'), 'created_at'),
    );

    $teammate = User::factory()->create();
    $this->team->users()->attach($teammate, ['role' => 'admin']);
    $teammate->forceFill(['current_team_id' => $this->team->getKey()])->save();

    $this->actingAs($teammate);
    Filament::setTenant($this->team);

    livewire(ListCompanies::class)
        ->assertCanRenderTableColumn('created_at');
});

it('guarda una elección distinta por equipo', function (): void {
    $component = livewire(ListCompanies::class);

    $component->call(
        'applyTableColumnManager',
        withColumnToggledOff($component->get('tableColumns'), 'created_at'),
    );

    // Las columnas de campos personalizados son por tenant, así que la preferencia
    // también: en otro equipo el usuario parte de los valores por defecto.
    $otherTeam = User::factory()->withTeam()->create()->currentTeam;
    $otherTeam->users()->attach($this->user, ['role' => 'admin']);
    $this->user->forceFill(['current_team_id' => $otherTeam->getKey()])->save();

    Filament::setTenant($otherTeam);

    livewire(ListCompanies::class)
        ->assertCanRenderTableColumn('created_at');
});

it('configura por separado el listado y el relation manager de la misma entidad', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create();
    $company->notes()->saveMany(Note::factory(2)->recycle([$this->user, $this->team])->make());

    $relationManager = livewire(NotesRelationManager::class, [
        'ownerRecord' => $company,
        'pageClass' => ViewCompany::class,
    ]);

    $relationManager->call(
        'applyTableColumnManager',
        withColumnToggledOff($relationManager->get('tableColumns'), 'created_at'),
    );

    expect(UserPreference::query()->pluck('key')->all())
        ->toBe(['table_columns:'.NotesRelationManager::class]);

    session()->flush();

    livewire(ManageNotes::class)
        ->assertCanRenderTableColumn('created_at');
});

it('no escribe en cada visita al listado, solo cuando cambia algo', function (): void {
    $component = livewire(ListCompanies::class);

    $component->call(
        'applyTableColumnManager',
        withColumnToggledOff($component->get('tableColumns'), 'created_at'),
    );

    $writes = 0;

    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (str_contains($query->sql, 'user_preferences') && ! str_starts_with(mb_strtolower($query->sql), 'select')) {
            $writes++;
        }
    });

    livewire(ListCompanies::class)->assertOk();
    livewire(ListCompanies::class)->assertOk();

    expect($writes)->toBe(0);
});
