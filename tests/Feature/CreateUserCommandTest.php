<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

test('it creates an account with the given username', function () {
    $this->artisan('users:create', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'username' => 'janedoe',
    ])->assertSuccessful();

    $user = User::firstWhere('email', 'jane@example.com');

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Jane Doe')
        ->and($user->username)->toBe('janedoe');
});

test('it leaves the email unverified unless asked otherwise', function () {
    $this->artisan('users:create', [
        'name' => 'Sam Rivers',
        'email' => 'sam@example.com',
        'username' => 'samrivers',
    ])->assertSuccessful();

    expect(User::firstWhere('username', 'samrivers')->email_verified_at)->toBeNull();
});

test('it can mark the email as already verified', function () {
    $this->artisan('users:create', [
        'name' => 'Sam Rivers',
        'email' => 'sam@example.com',
        'username' => 'samrivers',
        '--verified' => true,
    ])->assertSuccessful();

    expect(User::firstWhere('username', 'samrivers')->email_verified_at)->not->toBeNull();
});

test('it never stores an operator-supplied password', function () {
    $this->artisan('users:create', [
        'name' => 'Alex Kim',
        'email' => 'alex@example.com',
        'username' => 'alexkim',
    ])->assertSuccessful();

    $user = User::firstWhere('username', 'alexkim');

    expect($user->password)->toStartWith('$2y$')
        ->and(Hash::check('password', $user->password))->toBeFalse();
});

test('it rejects a username the legacy intranet could not use', function (string $username) {
    $this->artisan('users:create', [
        'name' => 'Test Person',
        'email' => 'test@example.com',
        'username' => $username,
    ])->assertFailed();

    expect(User::count())->toBe(0);
})->with([
    'uppercase' => 'JaneDoe',
    'dotted' => 'jane.doe',
    'underscored' => 'jane_doe',
    'hyphenated' => 'jane-doe',
    'spaced' => 'jane doe',
    'empty' => '',
    'too long' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
]);

/**
 * The login field is lowercased before it is looked up, so a stored address carrying
 * capitals is unreachable on a case-sensitive database.
 */
test('it stores the email address lowercased', function () {
    $this->artisan('users:create', [
        'name' => 'Robin Vance',
        'email' => 'Robin.Vance@Example.COM',
        'username' => 'robinvance',
    ])->assertSuccessful();

    expect(User::firstWhere('username', 'robinvance')->email)->toBe('robin.vance@example.com');
});

test('it refuses to duplicate an existing email regardless of casing', function () {
    User::factory()->create(['email' => 'robin@example.com']);

    $this->artisan('users:create', [
        'name' => 'Impostor',
        'email' => 'Robin@Example.com',
        'username' => 'impostor',
    ])->assertFailed();

    expect(User::count())->toBe(1);
});

test('it refuses to duplicate an existing email', function () {
    User::factory()->create(['email' => 'jane@example.com']);

    $this->artisan('users:create', [
        'name' => 'Impostor',
        'email' => 'jane@example.com',
        'username' => 'impostor',
    ])->assertFailed();

    expect(User::count())->toBe(1);
});

test('it refuses to duplicate an existing username', function () {
    User::factory()->create(['username' => 'janedoe']);

    $this->artisan('users:create', [
        'name' => 'Impostor',
        'email' => 'impostor@example.com',
        'username' => 'janedoe',
    ])->assertFailed();

    expect(User::count())->toBe(1);
});

test('it emails a password-set link so no credential is ever typed at a terminal', function () {
    Notification::fake();

    $this->artisan('users:create', [
        'name' => 'Pat Morgan',
        'email' => 'pat@example.com',
        'username' => 'patmorgan',
    ])->assertSuccessful();

    Notification::assertSentTo(User::firstWhere('username', 'patmorgan'), ResetPassword::class);
});

/**
 * `password.reset` is a real route now, so the guard in `sendPasswordLink()` is only
 * reachable by emptying the route collection. It stays covered because the failure it
 * prevents is silent: the notification builds its URL from that route and throws
 * without it, which would abort the command after the account row was already written.
 */
test('it still creates the account when no reset route exists', function () {
    Notification::fake();

    Route::setRoutes(new RouteCollection);

    $this->artisan('users:create', [
        'name' => 'Pat Morgan',
        'email' => 'pat@example.com',
        'username' => 'patmorgan',
    ])->assertSuccessful();

    expect(User::firstWhere('username', 'patmorgan'))->not->toBeNull();

    Notification::assertNothingSent();
});
