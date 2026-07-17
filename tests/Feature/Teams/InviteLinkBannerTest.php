<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;

it('renders the team-name banner on login when intended url is a join link', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Acme Co', 'user_id' => $owner->id]);

    session(['url.intended' => route('teams.join', ['token' => $team->invite_link_token])]);

    $this->get('/app/login')
        ->assertOk()
        ->assertSee("You've been invited to join", false)
        ->assertSee('Acme Co');
});

/*
 | DIVERGE DE UPSTREAM a propósito. Upstream deja abrir el registro con un invite-link en sesión y
 | le pinta el banner del equipo; este fork lo cierra (addon srjingles/sr-crm, BlockRegistration):
 | el alta es SOLO por invitación NOMINAL, la que lleva un email contra el que validar.
 |
 | El motivo no es teórico: cada equipo recibe un invite_link_token al crearse (Team.php, Str::random(40)),
 | se use el enlace o no. Aceptarlo como pase de alta convierte cualquier token filtrado en un
 | auto-registro. La ruta /join/{token} exige `auth`, así que en la práctica un invitado nunca llega
 | al registro por esa vía: el closure redirectGuestsTo lo manda al login.
 |
 | El banner en el LOGIN sí sigue intacto (primer test del fichero), que es donde de verdad aparece.
 */
it('closes register to invite-link holders (signup is invitation-only)', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Acme Co', 'user_id' => $owner->id]);

    session(['url.intended' => route('teams.join', ['token' => $team->invite_link_token])]);

    $this->get('/app/register')
        ->assertRedirect(Filament::getPanel('app')->getLoginUrl());
});

it('does not render the banner when the join token has expired', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Stale Team', 'user_id' => $owner->id]);
    $team->forceFill(['invite_link_token_expires_at' => now()->subDay()])->save();

    session(['url.intended' => route('teams.join', ['token' => $team->invite_link_token])]);

    $this->get('/app/login')
        ->assertOk()
        ->assertDontSee('Stale Team');
});

it('does not render the banner when the intended url is unrelated', function (): void {
    session(['url.intended' => '/dashboard']);

    $this->get('/app/login')
        ->assertOk()
        ->assertDontSee("You've been invited to join", false);
});
