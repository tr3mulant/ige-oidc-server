<?php

use App\Models\User;

test('it sets the username on an account found by email', function () {
    $user = User::factory()->create(['email' => 'robin@example.com', 'username' => 'rvance']);

    $this->artisan('users:set-username', [
        'user' => 'robin@example.com',
        'username' => 'robinvance',
    ])->assertSuccessful();

    expect($user->fresh()->username)->toBe('robinvance');
});

test('it sets the username on an account found by its current username', function () {
    $user = User::factory()->create(['username' => 'rvance']);

    $this->artisan('users:set-username', [
        'user' => 'rvance',
        'username' => 'robinvance',
    ])->assertSuccessful();

    expect($user->fresh()->username)->toBe('robinvance');
});

/**
 * The regression this file exists for. `username` is deliberately absent from the
 * model's fillable list, so an implementation reaching for `update()` would report
 * success and change nothing — the same silent no-op `users:deactivate` guards against.
 */
test('the new username is actually persisted, not dropped by mass assignment', function () {
    User::factory()->create(['username' => 'rvance']);

    $this->artisan('users:set-username', [
        'user' => 'rvance',
        'username' => 'robinvance',
    ])->assertSuccessful();

    expect(User::firstWhere('username', 'robinvance'))->not->toBeNull()
        ->and(User::firstWhere('username', 'rvance'))->toBeNull();
});

test('it fails when no account matches', function () {
    $this->artisan('users:set-username', [
        'user' => 'nobody@example.com',
        'username' => 'nobody',
    ])->assertFailed();
});

/**
 * The same spellings `users:create` refuses. Both paths validate through
 * `App\Rules\Username`; if they ever diverged, an account could be created with a
 * spelling this command would reject, or corrected into one that cannot sign in.
 */
test('it rejects a username the legacy intranet could not use', function (string $username) {
    $user = User::factory()->create(['username' => 'rvance']);

    $this->artisan('users:set-username', [
        'user' => 'rvance',
        'username' => $username,
    ])->assertFailed();

    expect($user->fresh()->username)->toBe('rvance');
})->with([
    'uppercase' => 'RobinVance',
    'hyphenated' => 'robin-vance',
    'spaced' => 'robin vance',
    'empty' => '',
    'leading separator' => '.robinvance',
    'trailing separator' => 'robinvance_',
    'doubled separator' => 'robin..vance',
    'too long' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
]);

/**
 * The counterpart of the `users:create` acceptance case, kept here for the same reason
 * the rejection dataset is duplicated: these two commands must agree on every spelling,
 * or an account is created in a shape this command would refuse to correct into.
 */
test('it accepts the separated spellings the roster actually contains', function (string $username) {
    $user = User::factory()->create(['username' => 'rvance']);

    $this->artisan('users:set-username', [
        'user' => 'rvance',
        'username' => $username,
    ])->assertSuccessful();

    expect($user->fresh()->username)->toBe($username);
})->with([
    'dotted' => 'robin.vance',
    'underscored' => 'robin_vance',
    'both' => 'robin.vance_two',
]);

test('it refuses to take a username another account already holds', function () {
    User::factory()->create(['username' => 'robinvance']);
    $user = User::factory()->create(['username' => 'rvance']);

    $this->artisan('users:set-username', [
        'user' => 'rvance',
        'username' => 'robinvance',
    ])->assertFailed();

    expect($user->fresh()->username)->toBe('rvance');
});

test('it does nothing when the username is already the requested one', function () {
    $user = User::factory()->create(['username' => 'robinvance']);
    $updatedAt = $user->updated_at;

    $this->artisan('users:set-username', [
        'user' => 'robinvance',
        'username' => 'robinvance',
    ])->assertSuccessful();

    expect($user->fresh()->updated_at->eq($updatedAt))->toBeTrue();
});

test('it leaves the rest of the account alone', function () {
    $user = User::factory()->create([
        'name' => 'Robin Vance',
        'email' => 'robin@example.com',
        'username' => 'rvance',
    ]);

    $this->artisan('users:set-username', [
        'user' => 'rvance',
        'username' => 'robinvance',
    ])->assertSuccessful();

    $user->refresh();

    expect($user->name)->toBe('Robin Vance')
        ->and($user->email)->toBe('robin@example.com')
        ->and($user->is_active)->toBeTrue();
});
