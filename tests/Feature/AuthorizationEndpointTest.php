<?php

use App\Models\User;

/**
 * `admin9/laravel-oidc-server` registers Passport's authorize routes wrapped in
 * `discovery_middleware` (`routes/web.php:18`), a key meant for `/.well-known/*`, so
 * the endpoint arrives carrying `web` and nothing else. Before
 * `ProtectsAuthorizationEndpoint` existed, an unauthenticated request here produced a
 * 500 — the SSO entry point erroring on the exact case it exists to serve.
 */
$authorizeUrl = '/oauth/authorize?client_id=1&redirect_uri=https%3A%2F%2Ftools.test%2Fcallback&response_type=code&scope=openid+profile';

test('a guest is sent to sign in rather than shown an error', function () use ($authorizeUrl) {
    $this->get($authorizeUrl)->assertRedirect(route('login'));
});

test('the authorization request is remembered across the sign-in', function () use ($authorizeUrl) {
    $this->get($authorizeUrl);

    expect(session('url.intended'))->toContain('/oauth/authorize')
        ->and(session('url.intended'))->toContain('client_id=1');
});

test('an unverified email cannot complete an authorization', function () use ($authorizeUrl) {
    $this->actingAs(User::factory()->twoFactorEnabled()->unverified()->create())
        ->get($authorizeUrl)
        ->assertRedirect(route('verification.notice'));
});

test('a deactivated account cannot complete an authorization', function () use ($authorizeUrl) {
    $this->actingAs(User::factory()->twoFactorEnabled()->create(['is_active' => false]))
        ->get($authorizeUrl)
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('an un-enrolled account cannot complete an authorization', function () use ($authorizeUrl) {
    $this->actingAs(User::factory()->create())
        ->get($authorizeUrl)
        ->assertRedirect(route('two-factor.enroll'));
});
