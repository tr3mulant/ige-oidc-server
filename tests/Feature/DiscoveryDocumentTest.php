<?php

use Laravel\Passport\Passport;

/**
 * `/.well-known/openid-configuration` is a public statement of what this server will
 * accept, and it is the only thing a client is supposed to need. Every entry in it is
 * a commitment, so the document should describe the two flows this company actually
 * uses and nothing else — a narrower contract is a smaller surface to get wrong later.
 *
 * The package builds it straight from config (`OidcController.php:45-52`), so these
 * assertions are really about `config/oidc-server.php` not drifting back to defaults.
 */
beforeEach(function () {
    $this->discovery = $this->getJson('/.well-known/openid-configuration')->assertOk()->json();
});

test('only the authorization code flow is advertised', function () {
    expect($this->discovery['response_types_supported'])->toBe(['code']);
});

/**
 * The implicit flow returns tokens in the URL fragment with no client authentication.
 */
test('the implicit flow is not advertised', function () {
    expect($this->discovery['response_types_supported'])->not->toContain('token');
});

test('only the grants the registered clients use are advertised', function () {
    expect($this->discovery['grant_types_supported'])->toBe(['authorization_code', 'refresh_token']);
});

/**
 * `client_credentials` produces a token with no user behind it — no `sub`, so nothing
 * that could become `REMOTE_USER` on the legacy intranet.
 */
test('client credentials and the device grant are not advertised', function () {
    expect($this->discovery['grant_types_supported'])
        ->not->toContain('client_credentials')
        ->not->toContain('urn:ietf:params:oauth:grant-type:device_code');
});

/**
 * `plain` transmits the PKCE verifier unhashed, which defeats the point of PKCE.
 */
test('only hashed PKCE challenges are advertised', function () {
    expect($this->discovery['code_challenge_methods_supported'])->toBe(['S256']);
});

test('the device grant is switched off at the source, not merely unadvertised', function () {
    expect(Passport::$deviceCodeGrantEnabled)->toBeFalse();
});

/**
 * The endpoints a client actually resolves from this document. If any of these move or
 * disappear, every client breaks at once and the discovery document is the only place
 * that would have said so.
 */
test('the endpoints a client needs are all present', function () {
    expect($this->discovery)
        ->toHaveKeys([
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'userinfo_endpoint',
            'jwks_uri',
        ]);
});

test('id tokens are signed with RS256', function () {
    expect($this->discovery['id_token_signing_alg_values_supported'])->toBe(['RS256']);
});
