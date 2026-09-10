<?php

use App\Models\User;

test('security headers are set on a normal response', function () {
    $response = $this->get('/login');

    $response->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("form-action 'self'");
});

/**
 * `SetSecurityHeaders` is appended first so its response pass runs last. If it were
 * ordered after the gates, every redirect they produce would go out bare.
 */
test('security headers survive a redirect issued by another middleware', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('two-factor.enroll'))
        ->assertHeader('X-Frame-Options', 'DENY');
});

/**
 * Both allowances came over from `tools.*` and neither has a reason to exist here:
 * `unsafe-eval` served Alpine, and this application ships no JavaScript framework;
 * the Cloudflare origin served a Turnstile widget on a registration form that will
 * never exist at an identity provider.
 */
test('the policy does not carry allowances inherited from the app it was ported from', function () {
    $policy = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($policy)->not->toContain('unsafe-eval')
        ->and($policy)->not->toContain('cloudflare');
});
