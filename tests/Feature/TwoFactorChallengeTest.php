<?php

use App\Models\User;

/**
 * The property the whole design rests on. Under the implementation this replaced, a
 * user was signed in first and held back by middleware, which is why the authorize
 * endpoint needed its own two-factor gate. Fortify never establishes the session at
 * all: `RedirectIfTwoFactorAuthenticatable` parks the user id under `login.id` and the
 * guard login happens in a later pipeline stage that is never reached.
 */
test('a password alone does not sign in an enrolled user', function () {
    $user = User::factory()->twoFactorEnabled()->create();

    $this->post('/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

test('the challenge screen renders after a correct password', function () {
    $user = User::factory()->twoFactorEnabled()->create();

    $this->post('/login', ['login' => $user->email, 'password' => 'password']);

    $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertSee('Open your authenticator app')
        ->assertSee('Lost your device? Use a recovery code');
});

test('a wrong code leaves the user signed out', function () {
    $user = User::factory()->twoFactorEnabled()->create();

    $this->post('/login', ['login' => $user->email, 'password' => 'password']);

    $this->post(route('two-factor.login.store'), ['code' => '000000'])
        ->assertSessionHasErrors();

    $this->assertGuest();
});

test('a recovery code completes the challenge', function () {
    $user = User::factory()->twoFactorEnabled()->create();
    $recoveryCode = $user->recoveryCodes()[0];

    $this->post('/login', ['login' => $user->email, 'password' => 'password']);

    $this->post(route('two-factor.login.store'), ['recovery_code' => $recoveryCode])
        ->assertRedirect(route('account.security'));

    $this->assertAuthenticatedAs($user);
});

/**
 * Recovery codes are single-use; `replaceRecoveryCode()` swaps the spent one for a
 * fresh code rather than deleting it, so the user never runs the list down to nothing.
 */
test('a recovery code cannot be spent twice', function () {
    $user = User::factory()->twoFactorEnabled()->create();
    $recoveryCode = $user->recoveryCodes()[0];

    $this->post('/login', ['login' => $user->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['recovery_code' => $recoveryCode]);
    $this->post('/logout');

    expect($user->fresh()->recoveryCodes())->not->toContain($recoveryCode)
        ->and($user->fresh()->recoveryCodes())->toHaveCount(8);

    $this->post('/login', ['login' => $user->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['recovery_code' => $recoveryCode]);

    $this->assertGuest();
});

test('an un-enrolled user still reaches the enrollment gate, not the challenge', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('account.security'));

    $this->assertAuthenticatedAs($user);

    $this->get('/')->assertRedirect(route('two-factor.enroll'));
});
