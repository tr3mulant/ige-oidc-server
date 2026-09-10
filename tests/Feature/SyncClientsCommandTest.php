<?php

use Admin9\OidcServer\Models\OidcClient;
use Illuminate\Support\Facades\Hash;

/**
 * The command exists because `passport:client` in a deploy is not idempotent and its
 * secret cannot be read back. Both of those properties are what these tests pin: that a
 * second run finds the client instead of creating a sibling, and that the secret the
 * configuration supplies is the one the client can authenticate with — neither of which
 * is observable by reading the command.
 */
function configureClient(array $overrides = [], string $slug = 'tools'): array
{
    $definition = array_merge([
        'id' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
        'secret' => 'tools-secret-'.str_repeat('a', 32),
        'name' => 'Tools (tools.example.com)',
        'redirect_uris' => ['https://tools.example.com/auth/callback'],
    ], $overrides);

    config()->set('oidc-clients.clients', [$slug => $definition]);

    return $definition;
}

function registerConfiguredClient(array $overrides = [], string $slug = 'tools'): OidcClient
{
    $definition = configureClient($overrides, $slug);

    test()->artisan('clients:sync')->assertSuccessful();

    return OidcClient::findOrFail($definition['id']);
}

test('it registers a client under the id the configuration supplies', function () {
    $definition = configureClient();

    $this->artisan('clients:sync')->assertSuccessful();

    $client = OidcClient::findOrFail($definition['id']);

    expect($client->name)->toBe('Tools (tools.example.com)')
        ->and($client->redirect_uris)->toBe(['https://tools.example.com/auth/callback'])
        ->and($client->revoked)->toBeFalse();
});

/**
 * The same shape `OidcClientTest` pins for `passport:client`, asserted again here
 * because this command builds the row itself rather than calling that one. A public
 * client would hand out tokens without authenticating the client; an owned client would
 * put a consent screen in front of every sign-in; an extra grant would be a capability
 * nobody audited.
 */
test('the registered client is confidential, first party, and grants only code and refresh', function () {
    $client = registerConfiguredClient();

    expect($client->hasGrantType('authorization_code'))->toBeTrue()
        ->and($client->hasGrantType('refresh_token'))->toBeTrue()
        ->and($client->grant_types)->toHaveCount(2)
        ->and($client->confidential())->toBeTrue()
        ->and($client->firstParty())->toBeTrue();
});

/**
 * The property the whole inversion rests on. `Client::secret()` hashes on assignment,
 * so seeding a known secret works — and that is what lets the same value live in the
 * IdP, the client's configuration and the secret store without ever being transcribed.
 */
test('the configured secret is the one the registered client authenticates with', function () {
    $definition = configureClient();

    $this->artisan('clients:sync')->assertSuccessful();

    $client = OidcClient::findOrFail($definition['id']);

    expect(Hash::check($definition['secret'], $client->getAttributes()['secret']))->toBeTrue()
        ->and($client->getAttributes()['secret'])->not->toBe($definition['secret']);
});

/**
 * The defect this command was written to prevent. A bare `passport:client` on every
 * deploy accumulates clients, and the newest is not the one the applications hold a
 * secret for — so the symptom is not an error but a login that stops working.
 */
test('running it again finds the existing client rather than registering a second', function () {
    configureClient();

    $this->artisan('clients:sync')->assertSuccessful();
    $this->artisan('clients:sync')->assertSuccessful();

    expect(OidcClient::count())->toBe(1);
});

test('it never prints the secret', function () {
    $definition = configureClient();

    $this->artisan('clients:sync')
        ->doesntExpectOutputToContain($definition['secret'])
        ->assertSuccessful();
});

test('it brings the name and redirect uris into line with configuration', function () {
    $definition = configureClient();

    $this->artisan('clients:sync')->assertSuccessful();

    configureClient([
        'name' => 'Tools',
        'redirect_uris' => ['https://tools.example.com/auth/callback', 'https://tools.example.com/oidc/callback'],
    ]);

    $this->artisan('clients:sync')->assertSuccessful();

    $client = OidcClient::findOrFail($definition['id']);

    expect($client->name)->toBe('Tools')
        ->and($client->redirect_uris)->toBe([
            'https://tools.example.com/auth/callback',
            'https://tools.example.com/oidc/callback',
        ]);
});

/**
 * Rotation takes the IdP, the vhost and the client application out of agreement until
 * all three carry the new value, so it cannot be something a deploy does on its own. The
 * registered secret is unreadable, so a mismatch is equally likely to mean the
 * environment lost the right value as that someone intended a new one.
 */
test('it fails and changes nothing when the configured secret is not the registered one', function () {
    $original = configureClient();

    $this->artisan('clients:sync')->assertSuccessful();

    configureClient(['secret' => 'tools-secret-'.str_repeat('b', 32)]);

    $this->artisan('clients:sync')->assertFailed();

    $client = OidcClient::findOrFail($original['id']);

    expect(Hash::check($original['secret'], $client->getAttributes()['secret']))->toBeTrue();
});

test('--rotate-secret replaces the registered secret for the named client only', function () {
    $original = configureClient();

    $this->artisan('clients:sync')->assertSuccessful();

    $rotated = configureClient(['secret' => 'tools-secret-'.str_repeat('b', 32)]);

    $this->artisan('clients:sync', ['--rotate-secret' => ['tools']])->assertSuccessful();

    $client = OidcClient::findOrFail($original['id']);

    expect(Hash::check($rotated['secret'], $client->getAttributes()['secret']))->toBeTrue()
        ->and(Hash::check($original['secret'], $client->getAttributes()['secret']))->toBeFalse();
});

test('--rotate-secret naming a different client does not rotate this one', function () {
    $original = configureClient();

    $this->artisan('clients:sync')->assertSuccessful();

    configureClient(['secret' => 'tools-secret-'.str_repeat('b', 32)]);

    $this->artisan('clients:sync', ['--rotate-secret' => ['legacy']])->assertFailed();

    $client = OidcClient::findOrFail($original['id']);

    expect(Hash::check($original['secret'], $client->getAttributes()['secret']))->toBeTrue();
});

/**
 * A client that has acquired a grant this project does not issue is a security event,
 * not drift to be papered over. Rewriting it quietly would erase the evidence along with
 * the symptom, so the command refuses and says what it found.
 */
test('it fails when the registered client grants more than this project issues', function () {
    $client = registerConfiguredClient();

    $client->forceFill(['grant_types' => ['authorization_code', 'refresh_token', 'password']])->save();

    $this->artisan('clients:sync')->assertFailed();

    expect($client->fresh()->hasGrantType('password'))->toBeTrue();
});

test('it fails when the registered client is revoked', function () {
    $client = registerConfiguredClient();

    $client->forceFill(['revoked' => true])->save();

    $this->artisan('clients:sync')->assertFailed();
});

/**
 * Every one of these reaches the command as an environment variable, where a typo is
 * silent. The `javascript:` case is the one that matters most: a redirect URI is a
 * destination this server sends a browser to carrying an authorization code.
 */
test('it refuses a definition the environment got wrong, and registers nothing', function (array $overrides) {
    configureClient($overrides);

    $this->artisan('clients:sync')->assertFailed();

    expect(OidcClient::count())->toBe(0);
})->with([
    'an id that is not a uuid' => [['id' => 'tools-client']],
    'no id' => [['id' => null]],
    'no secret' => [['secret' => null]],
    'a secret short enough to guess' => [['secret' => 'short']],
    'no name' => [['name' => null]],
    'no redirect uri' => [['redirect_uris' => []]],
    'a redirect uri that is not a url' => [['redirect_uris' => ['tools.example.com/auth/callback']]],
    'a redirect uri with a scheme that is not http' => [['redirect_uris' => ['javascript:alert(1)']]],
]);

/**
 * Deleting these would cascade to every token they issued, on the word of an environment
 * variable that may simply be missing a slug. Reporting them is the whole intervention.
 */
test('it reports a registered client that no configuration claims, and leaves it alone', function () {
    $orphan = registerConfiguredClient([
        'id' => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
        'name' => 'Retired app',
    ], 'retired');

    configureClient();

    $this->artisan('clients:sync')
        ->expectsOutputToContain('Retired app')
        ->assertSuccessful();

    expect($orphan->fresh())->not->toBeNull()
        ->and(OidcClient::count())->toBe(2);
});

test('it succeeds and registers nothing when no relying parties are configured', function () {
    config()->set('oidc-clients.clients', []);

    $this->artisan('clients:sync')->assertSuccessful();

    expect(OidcClient::count())->toBe(0);
});
