<?php

use App\Models\User;

test('a deactivated account cannot sign in', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->post('/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('login');

    $this->assertGuest();
});

/**
 * The login check and the middleware cover different moments: one refuses a new
 * session, the other ends one already open. Deactivating somebody who is signed in at
 * the identity provider has to take effect on their next request, not at expiry.
 */
test('an account deactivated mid-session is signed out on the next request', function () {
    $user = User::factory()->twoFactorEnabled()->create();

    $this->actingAs($user)->get('/')->assertOk();

    // Assigned, not mass-assigned: `is_active` is not fillable, by design.
    $user->is_active = false;
    $user->save();

    $this->actingAs($user->fresh())->get('/')->assertRedirect(route('login'));

    $this->assertGuest();
});

test('users:deactivate revokes access by email or by username', function (string $column) {
    $user = User::factory()->create(['email' => 'robin@example.com', 'username' => 'robinvance']);

    $this->artisan('users:deactivate', ['user' => $user->{$column}])->assertSuccessful();

    expect($user->fresh()->is_active)->toBeFalse();
})->with(['email', 'username']);

test('users:deactivate can restore an account', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->artisan('users:deactivate', ['user' => $user->email, '--restore' => true])->assertSuccessful();

    expect($user->fresh()->is_active)->toBeTrue();
});

test('users:deactivate reports an unknown account rather than failing silently', function () {
    $this->artisan('users:deactivate', ['user' => 'nobody@example.com'])->assertFailed();
});

test('users:deactivate says so when there is nothing to do', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->artisan('users:deactivate', ['user' => $user->email])
        ->expectsOutputToContain('already deactivated')
        ->assertSuccessful();
});

/**
 * A column default is applied by the INSERT, so the in-memory instance would hold
 * `null` until reloaded — and `null` is falsy, which would read as deactivated.
 */
test('a newly created account is active in memory, not just in the database', function () {
    expect((new User)->is_active)->toBeTrue()
        ->and(User::factory()->create()->is_active)->toBeTrue();
});
