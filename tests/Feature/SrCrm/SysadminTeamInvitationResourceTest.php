<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Laravel\Jetstream\Mail\TeamInvitation as TeamInvitationMail;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use SrJingles\SrCrm\Filament\Sysadmin\Resources\TeamInvitationResource\Pages\ListTeamInvitations;

/*
 | Invitaciones de TODOS los equipos en el panel sysadmin (addon srjingles/sr-crm). El host solo las
 | muestra equipo a equipo, dentro de los ajustes de cada equipo, así que no había forma de auditar
 | a quién se ha invitado ni qué está caducado sin recorrerlos uno a uno.
 |
 | Ojo al montaje: el sysadmin corre con strictAuthorization() y su guard es 'sysadmin'
 | (SystemAdministrator, no User). El addon no puede escribir en el namespace de policies que el host
 | descubre para ese panel (Relaticle\SystemAdmin\Policies), así que registra la suya con
 | Gate::policy() explícito, que tiene precedencia sobre el guessing. Eso es lo que se fija aquí.
 */
beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

test('it lists invitations from every team, not just one', function () {
    $teamA = Team::factory()->create(['name' => 'Equipo A']);
    $teamB = Team::factory()->create(['name' => 'Equipo B']);

    $a = TeamInvitation::factory()->create(['team_id' => $teamA->id, 'email' => 'a@example.com']);
    $b = TeamInvitation::factory()->create(['team_id' => $teamB->id, 'email' => 'b@example.com']);

    livewire(ListTeamInvitations::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$a, $b]);
});

test('the expired filter separates live invitations from dead ones', function () {
    $team = Team::factory()->create();

    $live = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'expires_at' => now()->addDays(5),
    ]);

    $dead = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'expires_at' => now()->subDay(),
    ]);

    livewire(ListTeamInvitations::class)
        ->filterTable('expired')
        ->assertCanSeeTableRecords([$dead])
        ->assertCanNotSeeTableRecords([$live]);

    livewire(ListTeamInvitations::class)
        ->filterTable('pending')
        ->assertCanSeeTableRecords([$live])
        ->assertCanNotSeeTableRecords([$dead]);
});

test('resending from sysadmin revives an expired invitation', function () {
    Mail::fake();

    $team = Team::factory()->create();
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'stale@example.com',
        'expires_at' => now()->subDays(2),
    ]);

    livewire(ListTeamInvitations::class)
        ->callAction(TestAction::make('resend')->table($invitation));

    expect($invitation->fresh()->isExpired())->toBeFalse();

    Mail::assertSent(TeamInvitationMail::class, fn ($mail) => $mail->hasTo('stale@example.com'));
});

test('revoking from sysadmin deletes the invitation', function () {
    $team = Team::factory()->create();
    $invitation = TeamInvitation::factory()->create(['team_id' => $team->id]);

    livewire(ListTeamInvitations::class)
        ->callAction(TestAction::make('delete')->table($invitation));

    expect(TeamInvitation::query()->whereKey($invitation->getKey())->exists())->toBeFalse();
});

test('a regular user is not authorized over invitations', function () {
    // Gate::policy() es global, no por panel, así que la policy del addon puede recibir un User del
    // guard 'web'. Solo deben pasar los administradores del sistema: en el panel de la app el
    // permiso se decide sobre el Team ('updateTeamMember'), nunca sobre la invitación.
    $user = User::factory()->withPersonalTeam()->create();

    expect($user->can('viewAny', TeamInvitation::class))->toBeFalse();
});
