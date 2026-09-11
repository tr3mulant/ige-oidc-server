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
use Laravel\Passport\ClientRepository;

/**
 * Drives /oauth/authorize -> /oauth/token and returns the decoded token response.
 *
 * @return array{status: int, body: array<string, mixed>|null}
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

    return ['status' => $response->getStatusCode(), 'body' => $response->json()];
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
 * Measures the issued token rather than reading the config, because those disagree:
 * `IdTokenService` sets the ID token's expiry from the *access* token
 * (`expiresAt($accessToken->getExpiryDateTime())`) and never reads
 * `tokens.id_token_ttl`. This pins what is actually in force; change it deliberately if
 * the TTL is ever wired up.
 */
test('the id token lifetime is the access token lifetime, not the configured id_token_ttl', function () {
    $payload = idTokenPayload(completeAuthorizationCodeFlow($this)['body']['id_token']);

    $lifetime = (int) round($payload['exp'] - $payload['iat']);

    expect($lifetime)->toBe(config('oidc-server.tokens.access_token_ttl'))
        ->and($lifetime)->not->toBe(config('oidc-server.tokens.id_token_ttl'));
});

/*
|--------------------------------------------------------------------------
| Known deviations from OIDC
|--------------------------------------------------------------------------
|
| These pin behaviour that is wrong, not behaviour that is wanted. They exist so the
| deviations are discovered here rather than inside a client library, and so that fixing
| one is a deliberate act that breaks a test and prompts a client-side change, rather
| than a silent upgrade that changes what tokens look like.
|
*/

/**
 * The JWKS advertises a `kid` (`OidcController::generateKeyId()`) naming which key signed
 * a token; `IdTokenService` sets no such header. A client library that selects its
 * verification key by `kid` cannot do so here and must fall back to the sole published
 * key. Harmless while exactly one key exists — and unfixable-by-the-client the moment a
 * second one does, which is any key rotation.
 */
test('DEVIATION: the id token header has no kid, though the JWKS publishes one', function () {
    $header = idTokenHeader(completeAuthorizationCodeFlow($this)['body']['id_token']);
    $jwks = $this->getJson('/.well-known/jwks.json')->assertOk()->json();

    expect($header)->not->toHaveKey('kid')
        ->and($jwks['keys'][0])->toHaveKey('kid')
        ->and($jwks['keys'])->toHaveCount(1);
});

/**
 * `TokenResponseType::resolveNonce()` reads `request()->input('nonce')` during the *token*
 * request, but a nonce is sent on the *authorize* request — and neither Passport nor
 * league/oauth2-server persists one alongside the authorization code. So it is accepted
 * and dropped. A client must therefore not require the nonce to come back; one configured
 * to enforce it rejects every login.
 */
test('DEVIATION: a nonce sent to the authorize endpoint never reaches the id token', function () {
    $nonce = 'nonce-'.bin2hex(random_bytes(8));

    $payload = idTokenPayload(
        completeAuthorizationCodeFlow($this, 'openid profile email', $nonce)['body']['id_token']
    );

    expect($payload)->not->toHaveKey('nonce');
});

/**
 * `iat` and `exp` come back as floats while `auth_time` is an integer — the same concept
 * serialised two ways in one token. RFC 7519 permits a non-integer NumericDate, so this is
 * legal but unusual, and strict parsers have been known to reject it.
 */
test('DEVIATION: iat and exp are floats while auth_time is an integer', function () {
    $payload = idTokenPayload(completeAuthorizationCodeFlow($this)['body']['id_token']);

    expect($payload['iat'])->toBeFloat()
        ->and($payload['exp'])->toBeFloat()
        ->and($payload['auth_time'])->toBeInt();
});

/**
 * `auth_time` is supposed to report when the user authenticated. `IdTokenService` sets it
 * from the clock at token-build time, so it always equals `iat`. The consequence is that
 * `max_age` — a client asking "re-authenticate if the session is older than N" — cannot be
 * relied on. Nothing uses it today; this pins why it must not start.
 */
test('DEVIATION: auth_time reports token-issue time rather than authentication time', function () {
    $payload = idTokenPayload(completeAuthorizationCodeFlow($this)['body']['id_token']);

    expect($payload['auth_time'])->toBe((int) $payload['iat']);
});
