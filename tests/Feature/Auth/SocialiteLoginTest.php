<?php

declare(strict_types=1);

use App\Enums\SocialiteProvider;
use App\Http\Controllers\Auth\CallbackController;
use App\Http\Controllers\Auth\RedirectController;
use App\Models\User;
use App\Models\UserSocialAccount;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

mutates(CallbackController::class, RedirectController::class);

function makeSocialiteUser(string $id, string $name, string $email): SocialiteUser
{
    $user = new SocialiteUser;
    $user->id = $id;
    $user->name = $name;
    $user->email = $email;

    return $user;
}

test('redirect to socialite provider', function () {
    Socialite::fake(SocialiteProvider::GOOGLE->value);

    $response = $this->get(route('auth.socialite.redirect', ['provider' => SocialiteProvider::GOOGLE->value]));

    $response->assertRedirect();
});

/*
 | DIVERGE DE UPSTREAM a propósito. Upstream da de alta a cualquiera que llegue por login
 | social; este fork lo cierra (addon srjingles/sr-crm: el contrato CreatesNewSocialUsers lo
 | implementa InvitationOnlySocialUserCreator, que exige una invitación nominal en sesión).
 | El alta social CON invitación —el camino que sí crea usuario— la cubre
 | tests/Feature/SrCrm/InvitationSocialSignupTest.php.
 */
test('callback from socialite provider does not create a user without an invitation', function () {
    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('123456789', 'Test User', 'test@example.com'),
    );

    $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);

    $this->assertDatabaseMissing('user_social_accounts', [
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => '123456789',
    ]);

    $this->assertGuest();
});

test('callback from socialite provider logs in existing user when social account exists', function () {
    $user = User::factory()->withTeam()->create([
        'email' => 'existing@example.com',
        'name' => 'Existing User',
    ]);

    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => '123456789',
    ]);

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('123456789', 'Existing User', 'existing@example.com'),
    );

    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    $this->assertAuthenticated();
    $this->assertAuthenticatedAs($user);

    $response->assertRedirect(url()->getAppUrl());
});

test('callback from socialite provider links social account to existing user when email matches', function () {
    $user = User::factory()->withTeam()->create([
        'email' => 'existing@example.com',
        'name' => 'Existing User',
    ]);

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('123456789', 'Existing User', 'existing@example.com'),
    );

    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    $response->assertRedirect();

    $this->assertAuthenticated();
    $this->assertAuthenticatedAs($user);
});

test('callback from socialite provider handles error gracefully', function () {
    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        fn () => throw new Exception('Socialite error'),
    );

    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['login']);
});

test('callback from socialite provider handles missing code parameter', function () {
    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['login']);
    $response->assertSessionHas('errors');

    $errors = session('errors')->getBag('default');
    expect($errors->first('login'))->toBe('Authorization was cancelled or failed. Please try again.');
});
