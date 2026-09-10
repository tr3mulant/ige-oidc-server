<?php

use Admin9\OidcServer\Models\OidcClient;
use App\Models\User;
use Laravel\Passport\Passport;

/**
 * §1.6 of the SSO plan asserts a specific client shape — authorization code plus
 * refresh, confidential, first party — and the consequences of getting it wrong are
 * quiet ones. A public client would hand out tokens without authenticating the client;
 * a non-first-party client would put a consent screen in front of every sign-in. These
 * pin the shape that `passport:client` with no grant flags actually produces, so a
 * package upgrade that changes the default is a failing test rather than a discovery
 * made in production.
 */
function createIdpClient(string $name, string $redirectUri): OidcClient
{
    /**
     * No confirmation to answer here, and that is the point. `passport:client` offers
     * to enable the device flow whenever `Passport::$deviceCodeGrantEnabled` is true
     * (`ClientCommand.php:153-154`), which is Passport's default. `AppServiceProvider`
     * turns it off, so the prompt never appears and an operator cannot say yes to it.
     * If someone re-enables the grant, this call starts failing on an unanswered
     * question — which is the warning worth having.
     */
    test()->artisan('passport:client', [
        '--name' => $name,
        '--redirect_uri' => $redirectUri,
    ])->assertSuccessful();

    return OidcClient::where('name', $name)->sole();
}

test('the package client model is the one Passport uses', function () {
    expect(config('oidc-server.client_model'))->toBe(OidcClient::class)
        ->and(Passport::clientModel())->toBe(OidcClient::class);
});

test('a bare passport:client is an authorization code client with refresh', function () {
    $client = createIdpClient('Legacy intranet', 'https://app.example.com/intranet/redirect_uri');

    expect($client->hasGrantType('authorization_code'))->toBeTrue()
        ->and($client->hasGrantType('refresh_token'))->toBeTrue();
});

/**
 * The grants this project deliberately does not want. `password` exists to let a
 * client collect raw credentials, which is the thing the whole migration removes;
 * `client_credentials` carries no user identity, so it has no `sub` and could never
 * produce a `REMOTE_USER`.
 */
test('a bare passport:client grants neither password nor client credentials', function () {
    $client = createIdpClient('Tools', 'https://tools.example.com/auth/callback');

    expect($client->hasGrantType('password'))->toBeFalse()
        ->and($client->hasGrantType('client_credentials'))->toBeFalse()
        ->and($client->hasGrantType('implicit'))->toBeFalse();
});

test('the client is confidential, so it must authenticate at the token endpoint', function () {
    $client = createIdpClient('Legacy intranet', 'https://app.example.com/intranet/redirect_uri');

    expect($client->confidential())->toBeTrue()
        ->and($client->secret)->not->toBeNull();
});

/**
 * No consent screen: `OidcClient::skipsAuthorization()` returns `firstParty()`, and a
 * client created without an owner is first party. Company staff signing in to company
 * applications should not be asked to approve anything.
 */
test('a client created without an owner skips the consent screen', function () {
    $client = createIdpClient('Tools', 'https://tools.example.com/auth/callback');

    expect($client->firstParty())->toBeTrue()
        ->and($client->skipsAuthorization(User::factory()->create(), ['openid', 'profile']))->toBeTrue();
});

test('the registered redirect uri is stored exactly as given', function () {
    $client = createIdpClient('Legacy intranet', 'https://app.example.com/intranet/redirect_uri');

    expect($client->redirect_uris)->toBe(['https://app.example.com/intranet/redirect_uri']);
});
