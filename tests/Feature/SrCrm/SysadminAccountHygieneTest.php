<?php

declare(strict_types=1);

use App\Actions\Jetstream\CancelUserDeletion;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\URL;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use SrJingles\SrCrm\Filament\Sysadmin\Resources\AccountStateResource\Pages\ListAccountStates;

/*
 | Estado de las cuentas en el UserResource del sysadmin (addon srjingles/sr-crm).
 |
 | El hueco que cubre: borrar una cuenta no la borra, la PROGRAMA (30 días de gracia). Mientras
 | tanto el usuario existe y AcceptTeamInvitationController le rechaza las invitaciones con un 403
 | seco, pero el UserResource del host no muestra scheduled_deletion_at por ningún lado: reinvitas a
 | alguien "borrado", salta el 403 y no hay pantalla donde ver por qué. Pasó en producción.
 |
 | Va en un recurso propio del addon y no ampliando el UserResource del host porque este es final y su
 | tabla no se puede completar desde fuera: Table::configureUsing corre dentro de Table::make(), ANTES
 | de que el resource llame a ->columns([...]) —que REEMPLAZA—, así que un pushColumns() se perdía.
 */
beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

test('it surfaces the scheduled deletion date, which the host user list hides', function () {
    $doomed = User::factory()->create(['scheduled_deletion_at' => now()->addDays(30)]);

    livewire(ListAccountStates::class)
        ->assertOk()
        ->assertCanRenderTableColumn('scheduled_deletion_at')
        ->assertCanSeeTableRecords([$doomed]);
});

test('it shows how many teams an account belongs to', function () {
    $orphan = User::factory()->create();

    livewire(ListAccountStates::class)
        ->assertCanRenderTableColumn('all_teams_count')
        ->assertCanSeeTableRecords([$orphan]);
});

test('the filter isolates accounts scheduled for deletion', function () {
    $doomed = User::factory()->create(['scheduled_deletion_at' => now()->addDays(30)]);
    $healthy = User::factory()->create(['scheduled_deletion_at' => null]);

    livewire(ListAccountStates::class)
        ->filterTable('scheduled_for_deletion')
        ->assertCanSeeTableRecords([$doomed])
        ->assertCanNotSeeTableRecords([$healthy]);
});

test('the filter finds users who belong to no team', function () {
    $orphan = User::factory()->create();
    $member = User::factory()->withPersonalTeam()->create();

    livewire(ListAccountStates::class)
        ->filterTable('without_team')
        ->assertCanSeeTableRecords([$orphan])
        ->assertCanNotSeeTableRecords([$member]);
});

test('cancelling the deletion unblocks the account', function () {
    $doomed = User::factory()->withPersonalTeam()->create([
        'scheduled_deletion_at' => now()->addDays(30),
    ]);

    expect($doomed->isScheduledForDeletion())->toBeTrue();

    livewire(ListAccountStates::class)
        ->callAction(TestAction::make('cancelDeletion')->table($doomed));

    expect($doomed->fresh()->isScheduledForDeletion())->toBeFalse();
});

test('the cancel action only shows up on accounts that are actually scheduled', function () {
    $healthy = User::factory()->withPersonalTeam()->create(['scheduled_deletion_at' => null]);

    livewire(ListAccountStates::class)
        ->assertActionHidden(TestAction::make('cancelDeletion')->table($healthy));
});

test('deleting permanently purges the account for real', function () {
    $doomed = User::factory()->withPersonalTeam()->create([
        'email' => 'purgame@example.com',
        'scheduled_deletion_at' => now()->addDays(30),
    ]);
    $personalTeam = $doomed->ownedTeams()->first();

    livewire(ListAccountStates::class)
        ->callAction(TestAction::make('purge')->table($doomed));

    // Se delega en DeletesUsers (la acción del host que usa el cron de purga), así que se lleva
    // también el equipo personal: un delete a pelo lo habría dejado huérfano.
    expect(User::query()->whereKey($doomed->getKey())->exists())->toBeFalse()
        ->and(Team::query()->whereKey($personalTeam->getKey())->exists())->toBeFalse();
});

test('it refuses to purge an account that owns a workspace with other members', function () {
    // ScheduleUserDeletion tiene este guard, pero DeleteUser NO: llamándolo directamente nos
    // cargaríamos el espacio de trabajo de otra gente. Y el estado puede haber cambiado desde que
    // se programó el borrado.
    $owner = User::factory()->withPersonalTeam()->create([
        'scheduled_deletion_at' => now()->addDays(30),
    ]);
    $shared = Team::factory()->create([
        'user_id' => $owner->id,
        'name' => 'Equipo compartido',
        'personal_team' => false,
    ]);
    $shared->users()->attach(User::factory()->create(), ['role' => 'editor']);

    livewire(ListAccountStates::class)
        ->callAction(TestAction::make('purge')->table($owner));

    expect(User::query()->whereKey($owner->getKey())->exists())->toBeTrue()
        ->and(Team::query()->whereKey($shared->getKey())->exists())->toBeTrue();
});

test('delete permanently only shows up on accounts already scheduled', function () {
    // No es un "borrar usuario" genérico: es saltarse los 30 días de gracia de uno ya programado.
    $healthy = User::factory()->withPersonalTeam()->create(['scheduled_deletion_at' => null]);

    livewire(ListAccountStates::class)
        ->assertActionHidden(TestAction::make('purge')->table($healthy));
});

/*
 | El bucle que motivó todo esto. Estos dos son peticiones HTTP del panel de la APP, así que hay que
 | deshacer a mano el contexto que deja el beforeEach para el sysadmin; si no, fallan por motivos que
 | nada tienen que ver con lo que fijan:
 |
 |  - actingAs(..., 'web') explícito: actingAs(..., 'sysadmin') deja el guard por DEFECTO en
 |    'sysadmin' para todo el test, y $request->user() devolvería al administrador → 403 por email.
 |  - setCurrentPanel('app'): el host resuelve las policies SEGÚN EL PANEL ACTUAL
 |    (Gate::guessPolicyNamesUsing en AppServiceProvider). Con el panel en 'sysadmin', el
 |    Gate::authorize('addTeamMember') de AddTeamMember caería en la TeamPolicy del sysadmin, que no
 |    tiene ese método → 403. En producción no ocurre: el panel lo fija el middleware de la petición.
 */
function invitedAccountScheduledForDeletion(): array
{
    $team = Team::factory()->create();

    $user = User::factory()->withPersonalTeam()->create([
        'email' => 'readmitido@example.com',
        'email_verified_at' => now(),
        'scheduled_deletion_at' => now()->addDays(30),
    ]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $user->email,
        'role' => 'editor',
    ]);

    $acceptUrl = URL::signedRoute(
        'team-invitations.accept',
        ['invitation' => $invitation],
    );

    return [$team, $user, $acceptUrl];
}

test('an account scheduled for deletion cannot accept its invitation', function () {
    [, $user, $acceptUrl] = invitedAccountScheduledForDeletion();

    Filament::setCurrentPanel(Filament::getPanel('app'));

    // Esto es lo que se ve en producción: un 403 seco, sin pista de la causa en ninguna pantalla.
    $this->actingAs($user, 'web')->get($acceptUrl)->assertForbidden();
});

test('cancelling the deletion lets the account accept its invitation', function () {
    [$team, $user, $acceptUrl] = invitedAccountScheduledForDeletion();

    Filament::setCurrentPanel(Filament::getPanel('app'));

    resolve(CancelUserDeletion::class)->cancel($user);

    $this->actingAs($user->fresh(), 'web')->get($acceptUrl)->assertRedirect();

    expect($user->fresh()->belongsToTeam($team))->toBeTrue();
});
