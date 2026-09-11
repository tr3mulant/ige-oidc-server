<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;

/**
 * §1.11 — there is no home page. `/` decides nothing except where someone who arrived
 * with no destination should go.
 */
test('the root sends a guest to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
});

test('the root sends a signed-in person to their account security screen', function () {
    $this->actingAs(User::factory()->twoFactorEnabled()->create())
        ->get('/')
        ->assertRedirect(route('account.security'));
});

test('there is no welcome page', function () {
    expect(view()->exists('welcome'))->toBeFalse();
});

/**
 * An unvalidated redirect parameter on the root of an authentication host is a
 * phishing primitive: a real login page, on the real domain, that hands the visitor
 * onward to whoever crafted the link. Users are routed by OIDC's `redirect_uri` and
 * by nothing else.
 */
test('the root ignores a redirect parameter rather than honouring it', function (string $query) {
    $this->get('/?'.$query)->assertRedirect(route('login'));
})->with([
    'redirect' => 'redirect=https://evil.example/harvest',
    'return_to' => 'return_to=https://evil.example/harvest',
    'next' => 'next=//evil.example',
]);

/**
 * The enrollment gate runs in the `web` group, so it decides before this route's
 * controller ever runs. An account holding only a password must not reach anything.
 */
test('the root sends an un-enrolled person to enrollment instead', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('two-factor.enroll'));
});

test('the account security screen sends a guest to login', function () {
    $this->get(route('account.security'))->assertRedirect(route('login'));
});

/**
 * Deliberately *not* behind `password.confirm`. A fresh sign-in lands here, and
 * guarding the whole screen would demand the password a third time in a row from
 * someone who has just proved it twice. Nothing secret is rendered on it.
 */
test('the account security screen opens without a further password prompt', function () {
    $this->actingAs(User::factory()->twoFactorEnabled()->create())
        ->get(route('account.security'))
        ->assertOk();
});

test('the account security screen links to the recovery codes without showing them', function () {
    $user = User::factory()->twoFactorEnabled()->create();

    $response = $this->actingAs($user)
        ->get(route('account.security'))
        ->assertOk()
        ->assertSee(route('account.recovery-codes'));

    foreach ($user->recoveryCodes() as $code) {
        $response->assertDontSee($code);
    }
});

test('the account security screen names the person and the username issued as their claim', function () {
    $user = User::factory()->twoFactorEnabled()->create([
        'name' => 'Robin Vance',
        'username' => 'robinvance',
    ]);

    $this->actingAs($user)
        ->get(route('account.security'))
        ->assertSee('Robin Vance')
        ->assertSee('robinvance');
});

test('the account security screen sends an un-enrolled person to enrollment instead', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('account.security'))
        ->assertRedirect(route('two-factor.enroll'));
});

test('the recovery codes screen sends a guest to login', function () {
    $this->get(route('account.recovery-codes'))->assertRedirect(route('login'));
});

/**
 * The one screen that renders a secret, so the one screen that costs a password.
 * Without this, a borrowed session at an unlocked laptop reads the codes.
 */
test('the recovery codes screen requires a confirmed password', function () {
    $this->actingAs(User::factory()->twoFactorEnabled()->create())
        ->get(route('account.recovery-codes'))
        ->assertRedirect(route('password.confirm'));
});

test('the recovery codes screen shows every code once the password is confirmed', function () {
    $user = User::factory()->twoFactorEnabled()->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('account.recovery-codes'))
        ->assertOk();

    foreach ($user->recoveryCodes() as $code) {
        $response->assertSee($code);
    }
});

test('regenerating the recovery codes replaces every one of them', function () {
    $user = User::factory()->twoFactorEnabled()->create();
    $original = $user->recoveryCodes();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.regenerate-recovery-codes'))
        ->assertSessionHas('status', Fortify::RECOVERY_CODES_GENERATED);

    $replacement = $user->fresh()->recoveryCodes();

    expect($replacement)->toHaveCount(count($original))
        ->and(array_intersect($original, $replacement))->toBeEmpty();
});

test('regenerating the recovery codes requires a confirmed password', function () {
    $user = User::factory()->twoFactorEnabled()->create();
    $original = $user->recoveryCodes();

    $this->actingAs($user)
        ->post(route('two-factor.regenerate-recovery-codes'))
        ->assertRedirect(route('password.confirm'));

    expect($user->fresh()->recoveryCodes())->toBe($original);
});

test('moving to a new device requires a confirmed password to even see the confirmation step', function () {
    $this->actingAs(User::factory()->twoFactorEnabled()->create())
        ->get(route('account.new-device'))
        ->assertRedirect(route('password.confirm'));
});

/**
 * The reason this step is a GET. `Redirector::guest()` records `previous()` as the
 * intended URL for a non-GET request, so posting the DELETE straight from the account
 * screen would return the person there after confirming, with nothing done. Arriving
 * by GET makes this page the intended URL, so confirming lands here ready to act.
 */
test('the new device screen is where the password confirmation returns you, ready to act', function () {
    $user = User::factory()->twoFactorEnabled()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('account.new-device'))
        ->assertOk()
        ->assertSee(__('Remove it and enrol a new one'));
});

/**
 * "Disable" is Fortify's name for it; here it is the first half of moving phones.
 * What matters is that the account cannot come to rest without a second factor.
 */
test('moving to a new device clears the enrollment and forces a fresh one', function () {
    $user = User::factory()->twoFactorEnabled()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'))
        ->assertSessionHas('status', Fortify::TWO_FACTOR_AUTHENTICATION_DISABLED);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();

    $this->actingAs($user->fresh())
        ->get('/')
        ->assertRedirect(route('two-factor.enroll'));
});

test('clearing the enrollment requires a confirmed password', function () {
    $user = User::factory()->twoFactorEnabled()->create();

    $this->actingAs($user)
        ->delete(route('two-factor.disable'))
        ->assertRedirect(route('password.confirm'));

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

/**
 * `Features::updatePasswords()` is enabled, so `PUT /user/password` has been live since
 * the auth surface landed. Until §1.11 it had no interface at all.
 */
test('the password change form is on the account security screen', function () {
    $this->actingAs(User::factory()->twoFactorEnabled()->create())
        ->get(route('account.security'))
        ->assertSee(route('user-password.update'));
});

test('changing a password succeeds when the current one is given', function () {
    $user = User::factory()->twoFactorEnabled()->create();

    $this->actingAs($user)
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-elephant-carpet-99',
            'password_confirmation' => 'new-elephant-carpet-99',
        ])
        ->assertSessionHas('status', Fortify::PASSWORD_UPDATED);

    expect(Hash::check('new-elephant-carpet-99', $user->fresh()->password))->toBeTrue();
});

/**
 * `UpdateUserPassword` validates into a named bag (`UpdateUserPassword.php:29`), so
 * the form has to read that bag or a rejected password looks accepted.
 */
test('changing a password is refused without the current one, into the bag the form reads', function () {
    $user = User::factory()->twoFactorEnabled()->create();

    $this->actingAs($user)
        ->put(route('user-password.update'), [
            'current_password' => 'not-the-password',
            'password' => 'new-elephant-carpet-99',
            'password_confirmation' => 'new-elephant-carpet-99',
        ])
        ->assertSessionHasErrors(['current_password'], null, 'updatePassword');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('a guest cannot change anybody\'s password', function () {
    $this->put(route('user-password.update'), [
        'current_password' => 'password',
        'password' => 'new-elephant-carpet-99',
        'password_confirmation' => 'new-elephant-carpet-99',
    ])->assertRedirect(route('login'));
});
