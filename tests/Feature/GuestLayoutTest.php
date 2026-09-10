<?php

use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Every screen this application renders shares one layout, and the heading is a prop on
 * it rather than markup in each page.
 *
 * The regression these tests exist for: when the heading lived in the layout *and* in
 * the pages, every page that had its own `<h1>` rendered two of them — a broken document
 * outline for a screen reader — and every page was titled after whichever one sat in the
 * layout, so the account-security screen announced itself as "Sign In". Both failures are
 * invisible unless something counts.
 */
function assertPageHeading(TestResponse $response, string $heading): void
{
    $html = $response->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1, 'expected exactly one <h1> on the page')
        ->and($html)->toContain('<title>'.$heading.' - IGE Account</title>')
        ->and($html)->toContain('>'.$heading.'</h1>');
}

test('the sign-in page', function () {
    assertPageHeading($this->get(route('login')), 'Sign in');
});

test('the sign-in page carries the only subheading', function () {
    $this->get(route('login'))->assertSee('Using your IGE account');
});

test('the forgotten-password page', function () {
    assertPageHeading($this->get(route('password.request')), 'Reset your password');
});

test('the password reset page', function () {
    assertPageHeading(
        $this->get(route('password.reset', ['token' => 'a-token'])),
        'Choose a new password',
    );
});

test('the password confirmation page', function () {
    assertPageHeading(
        $this->actingAs(User::factory()->twoFactorEnabled()->create())->get(route('password.confirm')),
        'Confirm your password',
    );
});

test('the email verification page', function () {
    $user = User::factory()->twoFactorEnabled()->unverified()->create();

    assertPageHeading($this->actingAs($user)->get(route('verification.notice')), 'Verify your email address');
});

test('the account security page', function () {
    assertPageHeading(
        $this->actingAs(User::factory()->twoFactorEnabled()->create())->get(route('account.security')),
        'Your account security',
    );
});

test('the recovery codes page', function () {
    $response = $this->actingAs(User::factory()->twoFactorEnabled()->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('account.recovery-codes'));

    assertPageHeading($response, 'Recovery codes');
});

test('the new device page', function () {
    $response = $this->actingAs(User::factory()->twoFactorEnabled()->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('account.new-device'));

    assertPageHeading($response, 'Move to a new device');
});

/**
 * Enrollment is the one screen whose heading is computed rather than literal — it has
 * three states — which is why it is the one most likely to lose the invariant.
 */
test('the enrollment page, before starting', function () {
    assertPageHeading(
        $this->actingAs(User::factory()->create())->get(route('two-factor.enroll')),
        'Set up two-factor authentication',
    );
});

test('the enrollment page, awaiting confirmation', function () {
    $user = User::factory()->twoFactorEnabled()->create(['two_factor_confirmed_at' => null]);

    assertPageHeading($this->actingAs($user)->get(route('two-factor.enroll')), 'Scan this code');
});

test('the two-factor challenge page', function () {
    $user = User::factory()->twoFactorEnabled()->create();

    $this->post(route('login.store'), ['login' => $user->email, 'password' => 'password']);

    assertPageHeading($this->get(route('two-factor.login')), 'Two-factor authentication');
});
