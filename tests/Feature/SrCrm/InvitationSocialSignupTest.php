<?php

declare(strict_types=1);

use App\Contracts\User\CreatesNewSocialUsers;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use SrJingles\SrCrm\Auth\InvitationOnlySocialUserCreator;
use SrJingles\SrCrm\Exceptions\SocialRegistrationDisabledException;

/*
 | Alta con login social SOLO por invitación (addon srjingles/sr-crm, modo
 | srcrm.auth.social_registration = 'invitation'). Sin esto un invitado no podía completar su alta
 | con Google: el creador anterior rechazaba TODAS las altas sociales, así que quien no tenía cuenta
 | no podía ni entrar ni crearla.
 |
 | Se ejercita el contrato del host (CreatesNewSocialUsers), que es exactamente lo que el
 | CallbackController resuelve e invoca —y solo para altas nuevas: a los usuarios existentes los
 | resuelve antes por cuenta social o por email, sin pasar por create()—. Lo que queda fuera a
 | propósito es el viaje OAuth a Google: eso es Socialite, no nuestro gate.
 */
beforeEach(function (): void {
    $this->team = Team::factory()->create();
});

function invitationInSession(Team $team, string $email): TeamInvitation
{
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $email,
        'role' => 'editor',
    ]);

    // Lo que deja el middleware `auth` cuando el invitado abre el enlace de su email.
    session()->put('url.intended', URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]));

    return $invitation;
}

test('the invitation-only creator is what the host resolves', function () {
    expect(app(CreatesNewSocialUsers::class))->toBeInstanceOf(InvitationOnlySocialUserCreator::class);
});

test('an invited user can create their account with a social login', function () {
    invitationInSession($this->team, 'brandnew@example.com');

    $user = app(CreatesNewSocialUsers::class)->create([
        'name' => 'Brand New',
        'email' => 'brandnew@example.com',
        'terms' => 'on',
    ]);

    // Delegamos en CreateNewSocialUser del host, así que el email queda verificado: es lo que
    // deja pasar luego el middleware `verified` de la ruta de aceptación de la invitación.
    expect($user->email)->toBe('brandnew@example.com')
        ->and($user->hasVerifiedEmail())->toBeTrue();
});

test('the social email must match the invited one', function () {
    invitationInSession($this->team, 'brandnew@example.com');

    // Sin esto, el enlace de invitación sería un pase libre: cualquiera que lo tuviese entraría
    // con la cuenta de Google que quisiera.
    expect(fn () => app(CreatesNewSocialUsers::class)->create([
        'name' => 'Otro',
        'email' => 'otra-cuenta@example.com',
        'terms' => 'on',
    ]))->toThrow(SocialRegistrationDisabledException::class);

    expect(User::query()->where('email', 'otra-cuenta@example.com')->exists())->toBeFalse();
});

test('the email match ignores casing', function () {
    invitationInSession($this->team, 'brandnew@example.com');

    $user = app(CreatesNewSocialUsers::class)->create([
        'name' => 'Brand New',
        'email' => 'BrandNew@Example.COM',
        'terms' => 'on',
    ]);

    expect($user->exists)->toBeTrue();
});

test('social signup is rejected without an invitation', function () {
    expect(fn () => app(CreatesNewSocialUsers::class)->create([
        'name' => 'Colado',
        'email' => 'colado@example.com',
        'terms' => 'on',
    ]))->toThrow(SocialRegistrationDisabledException::class);

    expect(User::query()->where('email', 'colado@example.com')->exists())->toBeFalse();
});

test('social signup is rejected with an expired invitation', function () {
    $invitation = invitationInSession($this->team, 'brandnew@example.com');
    $invitation->forceFill(['expires_at' => now()->subDay()])->save();

    expect(fn () => app(CreatesNewSocialUsers::class)->create([
        'name' => 'Brand New',
        'email' => 'brandnew@example.com',
        'terms' => 'on',
    ]))->toThrow(SocialRegistrationDisabledException::class);
});

test('a provider that withholds the email cannot slip through', function () {
    invitationInSession($this->team, 'brandnew@example.com');

    // El CallbackController fabrica este email cuando el proveedor no da ninguno; no debe casar.
    expect(fn () => app(CreatesNewSocialUsers::class)->create([
        'name' => 'Sin Email',
        'email' => 'google_12345@noemail.app',
        'terms' => 'on',
    ]))->toThrow(SocialRegistrationDisabledException::class);
});
