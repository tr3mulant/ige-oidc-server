<?php

use App\Models\User;
use Illuminate\Auth\Events\Login;

test('a password alone does not count as authenticating', function () {
    $user = User::factory()->twoFactorEnabled()->create();

    $this->post('/login', ['login' => $user->email, 'password' => 'password']);

    expect($user->fresh()->last_authenticated_at)->toBeNull();
});

test('completing the two-factor challenge records the authentication', function () {
    $user = User::factory()->twoFactorEnabled()->create();

    $this->post('/login', ['login' => $user->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['recovery_code' => $user->recoveryCodes()[0]]);

    expect($user->fresh()->last_authenticated_at)->not->toBeNull();
});

/**
 * `updated_at` is a claim of its own, meaning the profile changed. Driven by the event
 * rather than a sign-in, because spending a recovery code writes to the user too.
 */
test('recording the authentication does not look like a profile edit', function () {
    $user = User::factory()->create(['updated_at' => now()->subWeek()]);
    $before = $user->updated_at->timestamp;

    event(new Login('web', $user, false));

    expect($user->fresh()->updated_at->timestamp)->toBe($before)
        ->and($user->fresh()->last_authenticated_at)->not->toBeNull();
});
