<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\CompanyResource\RelationManagers\NotesRelationManager as CompanyNotesRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\PeopleRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\TasksRelationManager as CompanyTasksRelationManager;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\OpportunityResource\RelationManagers\NotesRelationManager as OpportunityNotesRelationManager;
use App\Filament\Resources\OpportunityResource\RelationManagers\TasksRelationManager as OpportunityTasksRelationManager;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\NotesRelationManager as PeopleNotesRelationManager;
use App\Filament\Resources\PeopleResource\RelationManagers\TasksRelationManager as PeopleTasksRelationManager;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

/**
 * Filament renders per-row Edit/Delete actions, which authorize each record
 * through its policy. Relation managers query the relationship directly, so
 * nothing eager-loads the record's `team`. Two or more rows arm Eloquent's
 * strict lazy-loading guard (Builder::hydrate only sets it for multi-row
 * results), so a policy that resolves `$record->team` throws there while a
 * single row silently passes.
 */
it('renders the :dataset relation manager with multiple records', function (string $relationManager, Closure $setUp): void {
    [$ownerRecord, $pageClass] = $setUp($this->user, $this->team);

    livewire($relationManager, [
        'ownerRecord' => $ownerRecord,
        'pageClass' => $pageClass,
    ])->assertOk();
})->with('crm relation managers');

/**
 * Siete relation managers pintan columnas de campos personalizados (las meten con
 * `...CustomFields::table()->forModel(...)->columns()` en su `table()`) y ninguno usa
 * `InteractsWithCustomFields`, que es quien hace el `with('customFieldValues...')` en los
 * listados principales. Parecía un N+1 de libro, y no lo es: Filament precarga por su
 * cuenta las relaciones que sus columnas necesitan, y la carga sale en UNA consulta
 * agrupada (`where entity_id in (...)`) para todas las filas.
 *
 * El test se queda como red: si alguien toca esas columnas o el modo en que se resuelven
 * y se vuelve a una consulta por fila, aquí salta. Medido: 1 consulta con 4 registros.
 */
it('no consulta los campos personalizados fila a fila en el relation manager :dataset', function (string $relationManager, Closure $setUp): void {
    [$ownerRecord, $pageClass] = $setUp($this->user, $this->team);

    $queries = 0;

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'custom_field_values')) {
            $queries++;
        }
    });

    livewire($relationManager, [
        'ownerRecord' => $ownerRecord,
        'pageClass' => $pageClass,
    ])->assertOk();

    // Con eager loading basta una consulta para todas las filas; sin él sale una por fila.
    expect($queries)->toBeLessThanOrEqual(1);
})->with('crm relation managers');

dataset('crm relation managers', [
    'company people' => [
        PeopleRelationManager::class,
        function (User $user, $team): array {
            $company = Company::factory()->recycle([$user, $team])->create();
            People::factory(4)->recycle([$user, $team])->create(['company_id' => $company->getKey()]);

            return [$company, ViewCompany::class];
        },
    ],
    'company notes' => [
        CompanyNotesRelationManager::class,
        function (User $user, $team): array {
            $company = Company::factory()->recycle([$user, $team])->create();
            $company->notes()->saveMany(Note::factory(4)->recycle([$user, $team])->make());

            return [$company, ViewCompany::class];
        },
    ],
    'company tasks' => [
        CompanyTasksRelationManager::class,
        function (User $user, $team): array {
            $company = Company::factory()->recycle([$user, $team])->create();
            $company->tasks()->saveMany(Task::factory(4)->recycle([$user, $team])->make());

            return [$company, ViewCompany::class];
        },
    ],
    'opportunity notes' => [
        OpportunityNotesRelationManager::class,
        function (User $user, $team): array {
            $opportunity = Opportunity::factory()->recycle([$user, $team])->create();
            $opportunity->notes()->saveMany(Note::factory(4)->recycle([$user, $team])->make());

            return [$opportunity, ViewOpportunity::class];
        },
    ],
    'opportunity tasks' => [
        OpportunityTasksRelationManager::class,
        function (User $user, $team): array {
            $opportunity = Opportunity::factory()->recycle([$user, $team])->create();
            $opportunity->tasks()->saveMany(Task::factory(4)->recycle([$user, $team])->make());

            return [$opportunity, ViewOpportunity::class];
        },
    ],
    'people notes' => [
        PeopleNotesRelationManager::class,
        function (User $user, $team): array {
            $people = People::factory()->recycle([$user, $team])->create();
            $people->notes()->saveMany(Note::factory(4)->recycle([$user, $team])->make());

            return [$people, ViewPeople::class];
        },
    ],
    'people tasks' => [
        PeopleTasksRelationManager::class,
        function (User $user, $team): array {
            $people = People::factory()->recycle([$user, $team])->create();
            $people->tasks()->saveMany(Task::factory(4)->recycle([$user, $team])->make());

            return [$people, ViewPeople::class];
        },
    ],
]);
