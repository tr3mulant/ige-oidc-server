<?php

/**
 * The refresh lifetime is the worst-case revocation latency for `is_active`: after a
 * deactivation, a client application can keep exchanging its refresh token for fresh
 * access tokens until this expires. That makes it a security parameter rather than a
 * tuning knob, and worth failing a build over if it drifts back to the package default
 * of seven days.
 */
test('token lifetimes are the ones the plan committed to', function () {
    expect(config('oidc-server.tokens.id_token_ttl'))->toBe(300)
        ->and(config('oidc-server.tokens.access_token_ttl'))->toBe(900)
        ->and(config('oidc-server.tokens.refresh_token_ttl'))->toBe(86400);
});

test('an id token does not outlive the redirect that carries it', function () {
    expect(config('oidc-server.tokens.id_token_ttl'))
        ->toBeLessThanOrEqual(config('oidc-server.tokens.access_token_ttl'));
});
