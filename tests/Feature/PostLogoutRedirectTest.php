<?php

/**
 * The package validates `post_logout_redirect_uri` against the client's `redirect_uris`
 * by path prefix. Both halves are wrong, and both fail quiet: a rejected URI produces a
 * redirect to this host rather than an error, so a client cannot tell "my post-logout
 * redirect worked" from "my post-logout redirect was ignored".
 *
 * These drive the real endpoint with a real `id_token_hint`, because the defect is only
 * observable in what the Location header says.
 */

use App\Models\OidcClient;
use App\Models\User;
use Illuminate\Support\Str;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

function registerLogoutClient(array $postLogoutUris = ['https://tools.example.com/']): OidcClient
{
    return OidcClient::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Tools',
        'secret' => 'secret-'.str_repeat('a', 32),
        'redirect_uris' => ['https://tools.example.com/auth/callback'],
        'post_logout_redirect_uris' => $postLogoutUris,
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
    ]);
}

/**
 * Only `aud` is read, and the package parses the hint without verifying its signature, so
 * this is signed with the real key purely to stay a well-formed token.
 */
function idTokenHintFor(OidcClient $client): string
{
    return (new Builder(new JoseEncoder, ChainedFormatter::withUnixTimestampDates()))
        ->issuedBy(config('oidc-server.issuer'))
        ->permittedFor($client->getKey())
        ->relatedTo('1')
        ->getToken(new Sha256, InMemory::file(storage_path('oauth-private.key')))
        ->toString();
}

function logoutTo(object $test, ?string $postLogoutRedirectUri, ?OidcClient $client = null): ?string
{
    $query = array_filter([
        'post_logout_redirect_uri' => $postLogoutRedirectUri,
        'id_token_hint' => $client ? idTokenHintFor($client) : null,
    ], fn ($value) => $value !== null);

    return $test->actingAs(User::factory()->twoFactorEnabled()->create())
        ->get('/oauth/logout?'.http_build_query($query))
        ->headers->get('Location');
}

/**
 * The finding itself. A client's own home page is not under its callback path, so the
 * prefix match refused it and dropped the user on this host instead.
 */
test('a registered post-logout redirect uri is honoured', function () {
    $client = registerLogoutClient();

    expect(logoutTo($this, 'https://tools.example.com/', $client))
        ->toBe('https://tools.example.com/');
});

/**
 * RP-Initiated Logout §2 requires an exact match. The prefix rule accepted anything under
 * the allowed path, which is how a stray URL became a valid logout destination.
 */
test('a uri under a registered one is not accepted', function () {
    $client = registerLogoutClient(['https://tools.example.com/signed-out']);

    expect(logoutTo($this, 'https://tools.example.com/signed-out/again', $client))
        ->toBe(url('/'));
});

/**
 * The OAuth callback is registered as a `redirect_uri`, not a post-logout one. That it is
 * no longer accepted is the point: the two lists are separate.
 */
test('a redirect uri is not by itself a valid post-logout destination', function () {
    $client = registerLogoutClient();

    expect(logoutTo($this, 'https://tools.example.com/auth/callback', $client))
        ->toBe(url('/'));
});

/**
 * "the OP MUST NOT perform post-logout redirection unless the OP has other means of
 * confirming the legitimacy of the post-logout redirection target." Without the hint
 * there is no client to confirm against.
 */
test('no redirect is performed without an id_token_hint', function () {
    registerLogoutClient();

    expect(logoutTo($this, 'https://tools.example.com/'))->toBe(url('/'));
});

/**
 * One client must not be able to send a user to another's landing page.
 */
test('a uri registered by a different client is refused', function () {
    registerLogoutClient(['https://tools.example.com/']);
    $other = registerLogoutClient(['https://app.example.com/intranet/']);

    expect(logoutTo($this, 'https://tools.example.com/', $other))->toBe(url('/'));
});

test('a foreign host is refused', function () {
    $client = registerLogoutClient();

    expect(logoutTo($this, 'https://evil.example.com/', $client))->toBe(url('/'));
});

/**
 * `state` is how a client correlates the logout it started with the arrival it gets back.
 */
test('state is carried through to an honoured redirect', function () {
    $client = registerLogoutClient();

    $location = $this->actingAs(User::factory()->twoFactorEnabled()->create())
        ->get('/oauth/logout?'.http_build_query([
            'post_logout_redirect_uri' => 'https://tools.example.com/',
            'id_token_hint' => idTokenHintFor($client),
            'state' => 'xyz-123',
        ]))->headers->get('Location');

    expect($location)->toBe('https://tools.example.com/?state=xyz-123');
});

/**
 * Whatever the redirect decision, the session must be gone — that is the logout.
 */
test('the session is invalidated even when the redirect is refused', function () {
    $client = registerLogoutClient();

    $this->actingAs(User::factory()->twoFactorEnabled()->create())
        ->get('/oauth/logout?'.http_build_query([
            'post_logout_redirect_uri' => 'https://evil.example.com/',
            'id_token_hint' => idTokenHintFor($client),
        ]));

    $this->assertGuest();
});

/**
 * The package advertised whatever the config key held — an empty array — while validating
 * against something else entirely. Discovery now reports what is actually honoured.
 */
test('discovery advertises the post-logout uris the clients registered', function () {
    registerLogoutClient(['https://tools.example.com/']);
    registerLogoutClient(['https://app.example.com/intranet/', 'https://tools.example.com/']);

    $advertised = $this->getJson('/.well-known/openid-configuration')
        ->assertOk()
        ->json('post_logout_redirect_uris_supported');

    expect($advertised)->toBe([
        'https://app.example.com/intranet/',
        'https://tools.example.com/',
    ]);
});
