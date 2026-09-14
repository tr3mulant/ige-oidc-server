<?php

/**
 * These complete a real authorization-code exchange rather than asserting configuration.
 * That distinction is the point of the file: `config/oidc-server.php`'s `user_model` was
 * null for the life of the project, which fatals `TokenResponseType` on every exchange,
 * and nothing caught it — `jwks.json` answers 200 off the public key alone, and the
 * existing lifetime tests read the config array. Only redeeming a code reaches the code
 * path that breaks.
 */

use App\Models\User;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * Drives /oauth/authorize -> /oauth/token and returns the decoded token response.
 *
 * @return array{status: int, body: array<string, mixed>|null, client: Client}
 */
function completeAuthorizationCodeFlow(object $test, string $scope = 'openid profile email', ?string $nonce = null, ?User $as = null): array
{
    $redirectUri = 'https://tools.example.com/auth/callback';

    $client = app(ClientRepository::class)
        ->createAuthorizationCodeGrantClient('Token exchange '.uniqid(), [$redirectUri], true);

    $verifier = str_repeat('a', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $query = array_filter([
        'client_id' => $client->getKey(),
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => $scope,
        'state' => 'state-123',
        'nonce' => $nonce,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ], fn ($v) => $v !== null);

    $redirect = $test->actingAs($as ?? User::factory()->twoFactorEnabled()->create())
        ->get('/oauth/authorize?'.http_build_query($query));

    parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $returned);

    $response = $test->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $returned['code'] ?? '',
        'redirect_uri' => $redirectUri,
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'code_verifier' => $verifier,
    ]);

    return ['status' => $response->getStatusCode(), 'body' => $response->json(), 'client' => $client];
}

/** @return array<string, mixed> */
function idTokenPart(string $idToken, int $index): array
{
    $part = explode('.', $idToken)[$index];

    return json_decode(base64_decode(strtr($part, '-_', '+/'), true), true) ?? [];
}

/** @return array<string, mixed> */
function idTokenPayload(string $idToken): array
{
    return idTokenPart($idToken, 1);
}

/** @return array<string, mixed> */
function idTokenHeader(string $idToken): array
{
    return idTokenPart($idToken, 0);
}

/**
 * The regression that matters. A null `user_model` reaches `null::find()` and returns a
 * 500 here while every browser-visible step — login, 2FA, the redirect carrying a valid
 * code — keeps working, so this is the only place the failure is observable.
 */
test('an authorization code can actually be exchanged for a token', function () {
    $result = completeAuthorizationCodeFlow($this);

    expect($result['status'])->toBe(200)
        ->and($result['body'])->toHaveKeys(['id_token', 'access_token', 'refresh_token']);
});

/**
 * `preferred_username` is what the legacy intranet turns into REMOTE_USER, and `sub` is
 * what `tools.*` stores as `oidc_sub`. Both fail silently: an absent claim produces an
 * empty REMOTE_USER, which `ADMIN_ML_USERS` historically treated as an admin.
 */
test('the id token carries the claims both client applications join on', function () {
    $user = User::factory()->twoFactorEnabled()->create();

    $redirectUri = 'https://tools.example.com/auth/callback';
    $client = app(ClientRepository::class)
        ->createAuthorizationCodeGrantClient('Claims '.uniqid(), [$redirectUri], true);

    $verifier = str_repeat('a', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $redirect = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'openid profile email',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]));

    parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

    $body = $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $query['code'] ?? '',
        'redirect_uri' => $redirectUri,
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'code_verifier' => $verifier,
    ])->json();

    $payload = idTokenPayload($body['id_token']);

    expect($payload['preferred_username'])->toBe($user->username)
        ->and($payload['sub'])->toBe((string) $user->getKey())
        ->and($payload['email'])->toBe($user->email);
});

/**
 * `email` and `email_verified` live in their own scope, so a client that requests only
 * `openid profile` receives a perfectly valid token with no email in it. `tools.*` links
 * an existing account on the verified email, and would silently provision duplicates
 * instead. Pinned so the scope list cannot be trimmed without this failing.
 */
test('email claims require the email scope and are absent without it', function () {
    $withEmail = completeAuthorizationCodeFlow($this, 'openid profile email');
    $without = completeAuthorizationCodeFlow($this, 'openid profile');

    expect(idTokenPayload($withEmail['body']['id_token']))->toHaveKey('email')
        ->and(idTokenPayload($without['body']['id_token']))->not->toHaveKey('email');
});

/**
 * A client rejects a token whose `iss` is not character-for-character the `issuer` it
 * read from discovery. Nothing guarantees that here: `OidcController::discovery()` trims
 * a trailing slash and `IdTokenService` does not, so the two are equal only because the
 * configured issuer happens to carry no slash.
 */
test('the id token issuer is exactly what the discovery document advertises', function () {
    $payload = idTokenPayload(completeAuthorizationCodeFlow($this)['body']['id_token']);
    $discovery = $this->getJson('/.well-known/openid-configuration')->assertOk()->json();

    expect($payload['iss'])->toBe($discovery['issuer']);
});

/**
 * Measured on an issued token rather than read back from the config, because that is the
 * distinction the finding turned on: `id_token_ttl` sat unread for the life of the
 * project while a test asserting the config value stayed green.
 */
test('the id token lifetime is the configured id_token_ttl', function () {
    $payload = idTokenPayload(completeAuthorizationCodeFlow($this)['body']['id_token']);
    $ttl = config('oidc-server.tokens.id_token_ttl');

    $lifetime = $payload['exp'] - $payload['iat'];

    /*
     * The one second of slack is clock granularity, not tolerance for drift: `exp` and
     * `iat` are two reads microseconds apart, and now that both are floored to whole
     * seconds, a pair that straddles a second boundary loses one. Widen it further only
     * with a reason.
     */
    expect($lifetime)->toBeGreaterThanOrEqual($ttl - 1)
        ->and($lifetime)->toBeLessThanOrEqual($ttl)
        ->and($lifetime)->toBeLessThan(config('oidc-server.tokens.access_token_ttl'));
});

/**
 * Membership rather than `keys[0]`, because the point of naming the key at all is the
 * day a rotation publishes a second one. Compared against the live JWKS because
 * `OidcIdTokenService::keyId()` duplicates a protected method on `OidcController`.
 */
test('the id token names the key that signed it, and the JWKS publishes that key', function () {
    $header = idTokenHeader(completeAuthorizationCodeFlow($this)['body']['id_token']);
    $jwks = $this->getJson('/.well-known/jwks.json')->assertOk()->json();

    expect($header)->toHaveKey('kid')
        ->and(array_column($jwks['keys'], 'kid'))->toContain($header['kid']);
});

/**
 * The round-trip a strict client library enforces, and the one criterion this IdP used to
 * fail: the nonce was read off the token request, which never carries one.
 */
test('a nonce sent to the authorize endpoint comes back in the id token', function () {
    $nonce = 'nonce-'.bin2hex(random_bytes(8));

    $payload = idTokenPayload(
        completeAuthorizationCodeFlow($this, 'openid profile email', $nonce)['body']['id_token']
    );

    expect($payload['nonce'])->toBe($nonce);
});

/**
 * OIDC Core forbids the claim when the client sent no nonce, so an empty string or null
 * is not an acceptable stand-in for absence.
 */
test('no nonce claim is issued when the client sends none', function () {
    $payload = idTokenPayload(completeAuthorizationCodeFlow($this)['body']['id_token']);

    expect($payload)->not->toHaveKey('nonce');
});

/**
 * The nonce is held in the session between the authorize request and the auth code, so
 * the failure mode is a stale one leaking into the next login on the same browser.
 */
test('a nonce is not carried into a later authorization that omits one', function () {
    completeAuthorizationCodeFlow($this, 'openid profile email', 'nonce-'.bin2hex(random_bytes(8)));

    $second = completeAuthorizationCodeFlow($this);

    expect(idTokenPayload($second['body']['id_token']))->not->toHaveKey('nonce');
});

/**
 * A refresh exchange redeems no authorization code, and OIDC Core says the ID token it
 * mints should carry no nonce.
 */
test('an id token minted from a refresh token carries no nonce', function () {
    $first = completeAuthorizationCodeFlow($this, 'openid profile email', 'nonce-'.bin2hex(random_bytes(8)));

    $refreshed = $this->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $first['body']['refresh_token'],
        'client_id' => $first['client']->getKey(),
        'client_secret' => $first['client']->plainSecret,
        'scope' => 'openid profile email',
    ])->json();

    expect(idTokenPayload($refreshed['id_token']))->not->toHaveKey('nonce');
});

/**
 * `prompt=consent` moves code issuance to the approve POST, which carries only
 * `auth_token` and `state` — the reason the nonce goes through the session rather than
 * being read off whichever request happens to write the code.
 */
test('the nonce survives a consent screen, where a later request writes the code', function () {
    $redirectUri = 'https://tools.example.com/auth/callback';
    $client = app(ClientRepository::class)
        ->createAuthorizationCodeGrantClient('Consent '.uniqid(), [$redirectUri], true);

    $nonce = 'nonce-'.bin2hex(random_bytes(8));
    $verifier = str_repeat('a', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $this->actingAs(User::factory()->twoFactorEnabled()->create())
        ->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => 'state-123',
            'nonce' => $nonce,
            'prompt' => 'consent',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))->assertOk();

    $redirect = $this->post('/oauth/authorize', [
        'auth_token' => session('authToken'),
        'state' => 'state-123',
    ]);

    parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $returned);

    $body = $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $returned['code'] ?? '',
        'redirect_uri' => $redirectUri,
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'code_verifier' => $verifier,
    ])->json();

    expect(idTokenPayload($body['id_token'])['nonce'])->toBe($nonce);
});

/**
 * RFC 7519 permits a non-integer NumericDate, so the floats were legal — but the type was
 * decided by whether the clock happened to land on a whole second, not by anything in this
 * codebase. Integers are what the rest of the world emits, and what `auth_time` already
 * was.
 */
test('every timestamp in the id token is a whole number of seconds', function () {
    $payload = idTokenPayload(completeAuthorizationCodeFlow($this)['body']['id_token']);

    expect($payload['iat'])->toBeInt()
        ->and($payload['exp'])->toBeInt()
        ->and($payload['auth_time'])->toBeInt();
});

/**
 * An hour apart, because equal values would also be produced by the bug this replaced.
 */
test('auth_time reports when the user authenticated, not when the token was issued', function () {
    $user = User::factory()->twoFactorEnabled()->create([
        'last_authenticated_at' => now()->subHour(),
    ]);

    $payload = idTokenPayload(
        completeAuthorizationCodeFlow($this, 'openid profile email', null, $user)['body']['id_token']
    );

    expect($payload['auth_time'])->toBe($user->last_authenticated_at->timestamp)
        ->and($payload['auth_time'])->toBeLessThan($payload['iat']);
});
