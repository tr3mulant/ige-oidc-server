<?php

use App\Models\User;

test('the login screen can be rendered', function () {
    $this->get('/login')->assertOk();
});

test('a user can authenticate with their email address', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

test('a user can authenticate with their legacy username', function () {
    $user = User::factory()->create(['username' => 'robinvance']);

    $this->post('/login', [
        'login' => 'robinvance',
        'password' => 'password',
    ])->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

/**
 * The username column is lowercase by constraint, and PostgreSQL compares
 * case-sensitively, so an unfolded lookup would reject a correct credential.
 */
test('a username is matched regardless of how it was typed', function () {
    $user = User::factory()->create(['username' => 'robinvance']);

    $this->post('/login', [
        'login' => '  RobinVance ',
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
});

test('a user cannot authenticate with a wrong password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'login' => $user->email,
        'password' => 'not-the-password',
    ])->assertSessionHasErrors('login');

    $this->assertGuest();
});

test('an unknown identifier does not authenticate anyone', function () {
    User::factory()->create(['username' => 'robinvance']);

    $this->post('/login', [
        'login' => 'nobody',
        'password' => 'password',
    ])->assertSessionHasErrors('login');

    $this->assertGuest();
});

/**
 * These shapes match neither column — `App\Rules\Username` forbids them and they are
 * not addresses — so they are refused by validation, without a query or a lockout slot.
 */
test('an identifier that is neither an email nor a username is refused', function (string $login) {
    User::factory()->create(['username' => 'robinvance']);

    $this->post('/login', [
        'login' => $login,
        'password' => 'password',
    ])->assertSessionHasErrors('login');

    $this->assertGuest();
})->with([
    'dotted' => 'jane.doe',
    'underscored' => 'jane_doe',
    'hyphenated' => 'jane-doe',
    'inner space' => 'jane doe',
    'truncated address' => 'scott@',
    'too long' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
]);

/**
 * Fortify rate-limits through the `throttle:login` middleware rather than a validation
 * error, so a locked-out attempt is a 429 and never reaches the credential check. The
 * limiter is keyed on the lowercased identifier plus IP
 * (`FortifyServiceProvider::boot()`), so case variation cannot buy extra attempts.
 */
test('repeated failures lock the identifier out', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $ignored) {
        $this->post('/login', [
            'login' => $user->email,
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('login');
    }

    // The sixth attempt carries the correct password and must still be refused.
    $this->post('/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->assertTooManyRequests();

    $this->assertGuest();
});

test('varying capitalisation does not buy extra attempts', function () {
    $user = User::factory()->create(['username' => 'robinvance']);

    foreach (range(1, 5) as $ignored) {
        $this->post('/login', ['login' => 'robinvance', 'password' => 'wrong']);
    }

    $this->post('/login', [
        'login' => 'RobinVance',
        'password' => 'password',
    ])->assertTooManyRequests();

    $this->assertGuest();
});

test('a signed-in user is sent to the page they asked for', function () {
    $user = User::factory()->create();

    $this->withSession(['url.intended' => 'https://auth.test/oauth/authorize?client_id=1'])
        ->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ])
        ->assertRedirect('https://auth.test/oauth/authorize?client_id=1');
});

test('a user can log out', function () {
    $this->actingAs(User::factory()->create())
        ->post('/logout')
        ->assertRedirect('/');

    $this->assertGuest();
});

test('there is no registration route', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register')->assertNotFound();
});
