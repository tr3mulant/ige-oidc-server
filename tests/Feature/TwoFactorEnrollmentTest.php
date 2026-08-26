<?php

use App\Models\User;
use Laravel\Fortify\Fortify;

test('an un-enrolled user cannot reach anything but enrollment', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('two-factor.enroll'));
});

/**
 * The reason this middleware is appended to the `web` group rather than named on the
 * application's own routes. `/oauth/authorize` belongs to Passport, sits in the `web`
 * group, and is what mints tokens for every client application — so an account holding
 * only a password must not be able to complete a flow through it.
 */
test('an un-enrolled user cannot reach the authorize endpoint', function () {
    $this->actingAs(User::factory()->create())
        ->get('/oauth/authorize?client_id=1&redirect_uri=https%3A%2F%2Ftools.test%2Fcallback&response_type=code&scope=openid+profile')
        ->assertRedirect(route('two-factor.enroll'));
});

test('the enrollment page itself is reachable, or the redirect would loop', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('two-factor.enroll'))
        ->assertOk()
        ->assertSee('Set up two-factor authentication');
});

test('signing out is reachable while un-enrolled', function () {
    $this->actingAs(User::factory()->create())
        ->post('/logout')
        ->assertRedirect();

    $this->assertGuest();
});

test('an enrolled user passes through untouched', function () {
    $this->actingAs(User::factory()->twoFactorEnabled()->create())
        ->get('/')
        ->assertOk();
});

/**
 * A secret without a confirmation is a half-finished enrollment, and `'confirm' => true`
 * means it grants nothing. The gate has to keep holding, or an account could stall
 * mid-setup and be treated as protected.
 */
test('a started but unconfirmed enrollment still counts as un-enrolled', function () {
    $user = User::factory()->twoFactorEnabled()->create(['two_factor_confirmed_at' => null]);

    $this->actingAs($user)->get('/')->assertRedirect(route('two-factor.enroll'));

    $this->actingAs($user)
        ->get(route('two-factor.enroll'))
        ->assertOk()
        ->assertSee('Scan this code');
});

test('a guest is sent to login, not to enrollment', function () {
    $this->get(route('two-factor.enroll'))->assertRedirect(route('login'));
});

test('enabling two-factor requires a confirmed password', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('two-factor.enable'))
        ->assertRedirect(route('password.confirm'));
});

test('enabling two-factor stores a secret but does not yet enrol the user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'))
        ->assertSessionHas('status', Fortify::TWO_FACTOR_AUTHENTICATION_ENABLED);

    $user->refresh();

    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

/**
 * The failure lands in a named error bag, which is unique to this action in Fortify
 * (`ConfirmTwoFactorAuthentication.php:46`). The enrollment view has to read the same
 * bag or a wrong code produces a silently blank form.
 */
test('a wrong confirmation code does not enrol the user', function () {
    $user = User::factory()->twoFactorEnabled()->create(['two_factor_confirmed_at' => null]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.confirm'), ['code' => '000000'])
        ->assertSessionHasErrors(['code'], null, 'confirmTwoFactorAuthentication');

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});
