<?php

use App\Models\User;
use Laravel\Fortify\Fortify;

test('a guest is sent to login', function () {
    $this->get(route('two-factor.settings'))->assertRedirect(route('login'));
});

/**
 * The page prints recovery codes, so a borrowed session must not be enough to read them.
 * Without this the settings screen would be a cheaper route to the same secrets Fortify
 * guards behind `'confirmPassword' => true`.
 */
test('it requires a confirmed password', function () {
    $this->actingAs(User::factory()->twoFactorEnabled()->create())
        ->get(route('two-factor.settings'))
        ->assertRedirect(route('password.confirm'));
});

test('it shows the recovery codes to an enrolled user who has confirmed their password', function () {
    $user = User::factory()->twoFactorEnabled()->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('two-factor.settings'))
        ->assertOk();

    foreach ($user->recoveryCodes() as $code) {
        $response->assertSee($code);
    }
});

/**
 * Enrollment is mandatory, so there is no state in which this page is the right answer
 * for an account without a second factor — the enrollment gate has to win first.
 */
test('an un-enrolled user is sent to enrollment rather than here', function () {
    $this->actingAs(User::factory()->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('two-factor.settings'))
        ->assertRedirect(route('two-factor.enroll'));
});

test('regenerating replaces every recovery code', function () {
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

test('regenerating requires a confirmed password', function () {
    $user = User::factory()->twoFactorEnabled()->create();
    $original = $user->recoveryCodes();

    $this->actingAs($user)
        ->post(route('two-factor.regenerate-recovery-codes'))
        ->assertRedirect(route('password.confirm'));

    expect($user->fresh()->recoveryCodes())->toBe($original);
});

/**
 * "Disable" is Fortify's name for it; here it is the first half of moving to a new
 * phone. What matters is that the account does not come to rest without a second
 * factor — the enrollment gate must pick it up on the very next request.
 */
test('clearing the enrollment forces a fresh one rather than leaving the account unprotected', function () {
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
 * The link is the only way in — an unreachable management screen is the same as not
 * having one, and the stock welcome view pointed at a `/dashboard` that returns 404.
 */
test('the settings screen is reachable from the signed-in landing page', function () {
    $this->actingAs(User::factory()->twoFactorEnabled()->create())
        ->get('/')
        ->assertOk()
        ->assertSee(route('two-factor.settings'));
});
