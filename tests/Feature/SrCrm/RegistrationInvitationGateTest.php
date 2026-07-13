<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\URL;

/*
 | Registro SOLO por invitación (addon srjingles/sr-crm). Con SRCRM_REGISTRATION=false el
 | addon cierra el registro público pero deja completar el alta si la sesión trae una
 | invitación válida (BlockRegistration). Regresión del 500: un invitado NUEVO no logueado
 | sobre /team-invitations/{id} caía en el closure redirectGuestsTo del host, que devuelve
 | Filament::getRegistrationUrl(); al anular el registro con registration(null) eso era null
 | y el closure (tipado : string) petaba con TypeError. Aquí se fija que NO haya 500 y que el
 | gating por invitación funcione en ambos sentidos.
 */
beforeEach(function (): void {
    $this->team = Team::factory()->create();
});

test('guest invited as a NEW user is redirected to registration, not 500', function () {
    // Nadie con este email todavía: el closure redirectGuestsTo del host manda a getRegistrationUrl().
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'brandnew@example.com',
        'role' => 'editor',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->get($acceptUrl)
        ->assertRedirect(); // NO debe ser 500 (TypeError si getRegistrationUrl() == null)
});

test('guest invited as an EXISTING user is redirected to login', function () {
    $existing = User::factory()->withPersonalTeam()->create(['email' => 'member@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => $existing->email,
        'role' => 'editor',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->get($acceptUrl)
        ->assertRedirect();
});

test('register page is allowed when a valid invitation is in the intended session', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'brandnew@example.com',
        'role' => 'editor',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);
    $registerUrl = Filament::getPanel('app')->getRegistrationUrl();

    // getRegistrationUrl() no debe ser null (registro sigue habilitado en el panel).
    expect($registerUrl)->not->toBeNull();

    $this->withSession(['url.intended' => $acceptUrl])
        ->get($registerUrl)
        ->assertOk();
});

test('register page redirects to login without a valid invitation', function () {
    $registerUrl = Filament::getPanel('app')->getRegistrationUrl();

    $this->get($registerUrl)
        ->assertRedirect(Filament::getPanel('app')->getLoginUrl());
});
