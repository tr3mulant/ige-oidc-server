<?php

/**
 * Driven through the real endpoint with a real `id_token_hint`: the defect is only
 * observable in the Location header, and it fails quiet — a refused URI redirects rather
 * than erroring.
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

/** Signed with the real key only to stay well-formed; the hint is parsed unverified. */
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

/** The finding itself: a home page is not under the callback path, so it was refused. */
test('a registered post-logout redirect uri is honoured', function () {
    $client = registerLogoutClient();

    expect(logoutTo($this, 'https://tools.example.com/', $client))
        ->toBe('https://tools.example.com/');
});

/** §2 requires an exact match; the prefix rule accepted anything below the path. */
test('a uri under a registered one is not accepted', function () {
    $client = registerLogoutClient(['https://tools.example.com/signed-out']);

    expect(logoutTo($this, 'https://tools.example.com/signed-out/again', $client))
        ->toBe(route('login'));
});

/** The two lists are separate, so a registered callback is not a logout destination. */
test('a redirect uri is not by itself a valid post-logout destination', function () {
    $client = registerLogoutClient();

    expect(logoutTo($this, 'https://tools.example.com/auth/callback', $client))
        ->toBe(route('login'));
});

/** §2: no redirect the OP cannot confirm, and without the hint there is no client. */
test('no redirect is performed without an id_token_hint', function () {
    registerLogoutClient();

    expect(logoutTo($this, 'https://tools.example.com/'))->toBe(route('login'));
});

/** One client must not be able to send a user to another's landing page. */
test('a uri registered by a different client is refused', function () {
    registerLogoutClient(['https://tools.example.com/']);
    $other = registerLogoutClient(['https://app.example.com/intranet/']);

    expect(logoutTo($this, 'https://tools.example.com/', $other))->toBe(route('login'));
});

test('a foreign host is refused', function () {
    $client = registerLogoutClient();

    expect(logoutTo($this, 'https://evil.example.com/', $client))->toBe(route('login'));
});

/** `state` is how a client correlates the logout it started with the arrival back. */
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

/** Whatever the redirect decision, the session must be gone — that is the logout. */
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
 * Asserted on the rendered page rather than the session: flash survives one request, so
 * an extra redirect would spend it before anything displayed it.
 */
test('a refused redirect says the user was signed out', function () {
    $client = registerLogoutClient();

    $this->actingAs(User::factory()->twoFactorEnabled()->create())
        ->get('/oauth/logout?'.http_build_query([
            'post_logout_redirect_uri' => 'https://evil.example.com/',
            'id_token_hint' => idTokenHintFor($client),
        ]))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'You have been signed out.');

    $this->get(route('login'))->assertSee('You have been signed out.');
});

/** The message belongs to the fallback alone. */
test('an honoured redirect carries no signed-out message', function () {
    $client = registerLogoutClient();

    $this->actingAs(User::factory()->twoFactorEnabled()->create())
        ->get('/oauth/logout?'.http_build_query([
            'post_logout_redirect_uri' => 'https://tools.example.com/',
            'id_token_hint' => idTokenHintFor($client),
        ]))
        ->assertRedirect('https://tools.example.com/')
        ->assertSessionMissing('status');
});

/** The advertised list previously governed nothing. */
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
