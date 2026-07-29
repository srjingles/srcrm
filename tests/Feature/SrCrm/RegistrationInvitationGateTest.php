<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Register;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\URL;
use SrJingles\SrCrm\Http\Middleware\BlockRegistration;

/*
 | Registro SOLO por invitación (addon srjingles/sr-crm). Con SRCRM_REGISTRATION=false el
 | addon cierra el registro público pero deja completar el alta si la sesión trae una
 | invitación válida (BlockRegistration). Regresión del 500: un invitado NUEVO no logueado
 | sobre /team-invitations/{id} caía en el closure redirectGuestsTo del host, que devuelve
 | Filament::getRegistrationUrl(); al anular el registro con registration(null) eso era null
 | y el closure (tipado : string) petaba con TypeError. Aquí se fija que NO haya 500 y que el
 | gating por invitación funcione en ambos sentidos.
 |
 | OJO con el alcance: esta suite NO puede cazar el bug de 2026-07-17 (el gate montado como
 | middleware global corría antes de StartSession y rebotaba al login hasta a los invitados
 | legítimos). Entre peticiones de un mismo test el Store de sesión es el MISMO objeto y conserva
 | sus atributos en memoria, así que un middleware global los ve; en una petición real el proceso
 | es nuevo y el Store está vacío hasta StartSession. Por eso el orden del middleware se fija
 | aparte, como invariante estructural, en el último test de este fichero.
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

    // Se conduce el flujo REAL: es el guest sobre el enlace de aceptación quien siembra
    // url.intended (vía el closure redirectGuestsTo del host), no el test. Sembrarlo a mano con
    // withSession() arrancaba la sesión ANTES de la petición y por eso este test pasaba en verde
    // mientras producción estaba rota: el gate corría como middleware GLOBAL —antes de enrutar y
    // por tanto antes de StartSession—, así que en una petición real leía la sesión sin arrancar.
    $this->get($acceptUrl)->assertRedirect($registerUrl);

    $this->get($registerUrl)->assertOk();
});

test('register page redirects to login without a valid invitation', function () {
    $registerUrl = Filament::getPanel('app')->getRegistrationUrl();

    $this->get($registerUrl)
        ->assertRedirect(Filament::getPanel('app')->getLoginUrl());
});

/*
 | El caso de uso completo: lo que el invitado hace de verdad, de punta a punta. Es el que estaba
 | roto —el invitado no podía ni entrar ni crear cuenta— y el que hay que mantener vivo.
 */
test('an invited user signs up with a password and ends up inside the team', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'brandnew-invited@gmail.com',
        'role' => 'editor',
    ]);

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    // 1. Pulsa el enlace de su email y aterriza en la pantalla de registro.
    $this->get($acceptUrl)->assertRedirect(Filament::getPanel('app')->getRegistrationUrl());

    // 2. Crea su contraseña.
    livewire(Register::class)
        ->fillForm([
            'name' => 'Brand New',
            'email' => 'brandnew-invited@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'brandnew-invited@gmail.com')->first();

    // El email queda verificado sin pasar por el correo de verificación: la invitación ya prueba
    // que la dirección es suya, y sin eso el middleware `verified` del paso 3 lo frenaría.
    expect($user)->not->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeTrue();

    // 3. Vuelve a la invitación (redirect()->intended) y entra en el equipo.
    $this->actingAs($user)->get($acceptUrl)->assertRedirect();

    expect($user->fresh()->belongsToTeam($this->team))->toBeTrue()
        ->and(TeamInvitation::query()->whereKey($invitation->getKey())->exists())->toBeFalse();
});

/*
 | Invariante de cableado del gate. Es un test estructural, no de comportamiento, porque el fallo
 | que cubre es invisible desde una petición de test (ver la nota de cabecera): BlockRegistration
 | necesita la sesión arrancada, así que TIENE que correr como middleware de ruta y después de
 | StartSession. Montado en el stack global corría antes de enrutar, leía la sesión vacía y mandaba
 | al login a todo el mundo, invitados incluidos: el alta por invitación nunca funcionó en producción.
 */
test('the registration gate runs after StartSession, never in the global stack', function () {
    $kernel = app(Kernel::class);
    $reflection = new ReflectionClass($kernel);

    $global = $reflection->getProperty('middleware');
    $global->setAccessible(true);

    expect($global->getValue($kernel))->not->toContain(BlockRegistration::class);

    $groups = $reflection->getProperty('middlewareGroups');
    $groups->setAccessible(true);

    // Cubre el /register del host y el POST register.store de Fortify.
    expect($groups->getValue($kernel)['web'])->toContain(BlockRegistration::class);

    // Cubre la página de registro del panel, y solo sirve si va detrás de StartSession.
    $panelMiddleware = array_values(Filament::getPanel('app')->getMiddleware());

    expect($panelMiddleware)->toContain(BlockRegistration::class)
        ->and(array_search(BlockRegistration::class, $panelMiddleware, true))
        ->toBeGreaterThan(array_search(StartSession::class, $panelMiddleware, true));
});
