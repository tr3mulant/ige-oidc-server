<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/**
 * Registers the relying parties declared in `config/oidc-clients.php`, and is safe to
 * re-run, so a deploy can call it on every push.
 *
 * The shape this exists to replace is a bare `passport:client` in the deploy, which is
 * a trap twice over. The command is not idempotent: each run creates an *additional*
 * client with a fresh id, and the newest is not the one the applications are configured
 * against. And its secret is unrecoverable, because `Client::secret()` hashes on
 * assignment and keeps the plaintext only in memory, so a generated secret has nowhere
 * useful to go — it has to reach Apache's `OIDCClientSecret` and the client app's
 * environment by hand, and a pipeline that generates one either loses it or prints it
 * into the build log.
 *
 * Inverting it fixes both: the id and the secret are inputs. Seeding a known secret
 * works precisely because assignment hashes it, so one value reaches all three systems
 * by construction. Nothing here ever prints a secret.
 *
 * What it will and will not change, and why the line falls where it does:
 *
 * - An absent client is created.
 * - Name and redirect URIs are updated to match configuration. They are non-secret and
 *   reviewable, and keeping them in step is what makes this a sync rather than a seed.
 * - The secret is never rewritten without `--rotate-secret`. Rotation takes the IdP,
 *   the vhost and the client application out of agreement until all three carry the new
 *   value, so it stays a deliberate act rather than a side effect of a deploy.
 * - Wrong grants, revocation and ownership are refused, never repaired. A client that
 *   has acquired a password grant or an owner is a security event; rewriting it quietly
 *   would erase the evidence along with the symptom.
 *
 * Registered clients that no configuration claims are reported and left alone. Pruning
 * them would cascade to every token they issued, on the word of an environment variable.
 */
#[Signature('clients:sync
    {--rotate-secret=* : Slug whose registered secret should be replaced with the configured one}')]
#[Description('Register the configured relying parties, or verify the ones already registered')]
class SyncClients extends Command
{
    /**
     * The only client shape this project issues: authorization code plus refresh,
     * confidential, first party. §1.6 of the SSO plan, pinned by `OidcClientTest`.
     *
     * @var list<string>
     */
    protected const GRANT_TYPES = ['authorization_code', 'refresh_token'];

    public function handle(): int
    {
        /** @var array<string, mixed> $configured */
        $configured = config('oidc-clients.clients', []);

        if ($configured === []) {
            $this->components->warn('No relying parties are configured, so nothing was registered.');
            $this->components->warn('Set OIDC_CLIENTS to a comma-separated list of slugs, then OIDC_CLIENT_{SLUG}_ID, _SECRET, _NAME and _REDIRECT_URIS for each.');

            return self::SUCCESS;
        }

        $succeeded = true;

        foreach ($configured as $slug => $definition) {
            $succeeded = $this->sync((string) $slug, $definition) && $succeeded;
        }

        $this->reportUnconfiguredClients($configured);

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{id: string, secret: string, name: string, redirect_uris: list<string>}|mixed  $definition
     */
    protected function sync(string $slug, mixed $definition): bool
    {
        $definition = $this->validated($slug, $definition);

        if ($definition === null) {
            return false;
        }

        $client = Passport::client()->newQuery()->find($definition['id']);

        return $client === null
            ? $this->register($slug, $definition)
            : $this->verify($slug, $definition, $client);
    }

    /**
     * @return array{id: string, secret: string, name: string, redirect_uris: list<string>}|null
     */
    protected function validated(string $slug, mixed $definition): ?array
    {
        $prefix = $this->environmentPrefix($slug);

        /**
         * `url:http,https` rather than a bare `url`: the rule otherwise accepts any
         * scheme, and a redirect URI is a destination this server sends a browser to
         * carrying an authorization code.
         *
         * The floor on secret length is here because nothing else checks it. The value
         * is operator-supplied rather than generated, and it is the client's entire
         * credential at the token endpoint.
         */
        $validator = Validator::make(
            is_array($definition) ? $definition : [],
            [
                'id' => ['required', 'string', 'uuid'],
                'secret' => ['required', 'string', 'min:32'],
                'name' => ['required', 'string', 'max:255'],
                'redirect_uris' => ['required', 'array', 'min:1'],
                'redirect_uris.*' => ['required', 'string', 'url:http,https'],
            ],
            attributes: [
                'id' => $prefix.'_ID',
                'secret' => $prefix.'_SECRET',
                'name' => $prefix.'_NAME',
                'redirect_uris' => $prefix.'_REDIRECT_URIS',
                'redirect_uris.*' => $prefix.'_REDIRECT_URIS',
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error("{$slug}: {$error}");
            }

            return null;
        }

        /** @var array{id: string, secret: string, name: string, redirect_uris: list<string>} $valid */
        $valid = $validator->validated();

        foreach ($valid['redirect_uris'] as $uri) {
            if (! str_starts_with($uri, 'https://')) {
                $this->components->warn("{$slug}: {$uri} is not https, so authorization codes for this client cross the network in the clear.");
            }
        }

        return $valid;
    }

    /**
     * @param  array{id: string, secret: string, name: string, redirect_uris: list<string>}  $definition
     */
    protected function register(string $slug, array $definition): bool
    {
        /**
         * The id is supplied rather than generated. `HasUuids` fills the key only when
         * it is empty, so a configured one survives — and that is exactly what makes
         * this idempotent, because the next run finds this row instead of creating a
         * sibling beside it.
         *
         * `owner_id` and `owner_type` are left null, which is what `firstParty()`
         * reads, and `OidcClient::skipsAuthorization()` returns. Staff signing in to
         * company applications are not asked to approve anything.
         */
        $client = Passport::client()->newInstance();

        $client->forceFill([
            'id' => $definition['id'],
            'name' => $definition['name'],
            'secret' => $definition['secret'],
            'redirect_uris' => $definition['redirect_uris'],
            'grant_types' => self::GRANT_TYPES,
            'revoked' => false,
        ])->save();

        $this->components->info("Registered {$slug} as \"{$definition['name']}\".");

        return true;
    }

    /**
     * @param  array{id: string, secret: string, name: string, redirect_uris: list<string>}  $definition
     */
    protected function verify(string $slug, array $definition, Client $client): bool
    {
        $sound = true;

        if ($client->revoked) {
            $this->components->error("{$slug}: the registered client is revoked and will refuse every token request.");
            $this->components->error("{$slug}: nothing was changed. Clear `revoked` on that row by hand, once you know what set it.");
            $sound = false;
        }

        if (! $client->firstParty()) {
            $this->components->error("{$slug}: the registered client has an owner, so every sign-in through it shows a consent screen.");
            $sound = false;
        }

        if (! $this->grantTypesMatch($client)) {
            $this->components->error(sprintf(
                '%s: the registered client grants [%s]; this project issues only [%s].',
                $slug,
                implode(', ', $client->grant_types),
                implode(', ', self::GRANT_TYPES),
            ));
            $sound = false;
        }

        if (! $client->confidential()) {
            $this->components->error("{$slug}: the registered client is public — it holds no secret, so it cannot authenticate at the token endpoint.");
            $sound = false;
        } elseif (! Hash::check($definition['secret'], $client->getAttributes()['secret'])) {
            $sound = $this->reconcileSecret($slug, $definition, $client) && $sound;
        }

        $this->syncAttributes($slug, $definition, $client);

        if ($sound) {
            $this->components->info("Verified {$slug}.");
        }

        return $sound;
    }

    /**
     * @param  array{id: string, secret: string, name: string, redirect_uris: list<string>}  $definition
     */
    protected function reconcileSecret(string $slug, array $definition, Client $client): bool
    {
        if (! in_array($slug, (array) $this->option('rotate-secret'), true)) {
            $this->components->error("{$slug}: the configured secret is not the registered one.");
            $this->components->error(sprintf(
                '%s: nothing was changed. The registered secret cannot be read back, so either restore the right value in %s_SECRET, or re-run with --rotate-secret=%s at a moment when the client application and its vhost can take the new one too.',
                $slug,
                $this->environmentPrefix($slug),
                $slug,
            ));

            return false;
        }

        $client->secret = $definition['secret'];
        $client->save();

        /**
         * Access tokens already issued keep working until they expire — rotation does
         * not revoke anything. What stops immediately is this client obtaining a *new*
         * token, because both the authorization-code exchange and the refresh require
         * it to authenticate with the secret it no longer has.
         */
        $this->components->warn("{$slug}: secret rotated. This client cannot obtain a new token until its own configuration carries the same value; tokens it already holds are unaffected.");

        return true;
    }

    /**
     * @param  array{id: string, secret: string, name: string, redirect_uris: list<string>}  $definition
     */
    protected function syncAttributes(string $slug, array $definition, Client $client): void
    {
        if ($client->name !== $definition['name']) {
            $this->components->info("{$slug}: name \"{$client->name}\" becomes \"{$definition['name']}\".");
            $client->name = $definition['name'];
        }

        if ($client->redirect_uris !== $definition['redirect_uris']) {
            $this->components->info(sprintf(
                '%s: redirect URIs [%s] become [%s].',
                $slug,
                implode(', ', $client->redirect_uris),
                implode(', ', $definition['redirect_uris']),
            ));

            $client->redirect_uris = $definition['redirect_uris'];
        }

        if ($client->isDirty()) {
            $client->save();
        }
    }

    protected function grantTypesMatch(Client $client): bool
    {
        $registered = $client->grant_types;
        sort($registered);

        $expected = self::GRANT_TYPES;
        sort($expected);

        return $registered === $expected;
    }

    /**
     * @param  array<string, mixed>  $configured
     */
    protected function reportUnconfiguredClients(array $configured): void
    {
        /**
         * Only well-formed ids reach the query. `oauth_clients.id` is a uuid column, so
         * a typo in `OIDC_CLIENT_*_ID` would otherwise reach PostgreSQL and abort the
         * command with a driver error — on top of the validation failure it has already
         * reported, and after any sound client alongside it was correctly registered.
         */
        $ids = [];

        foreach ($configured as $definition) {
            $id = is_array($definition) ? ($definition['id'] ?? null) : null;

            if (is_string($id) && Str::isUuid($id)) {
                $ids[] = $id;
            }
        }

        $unconfigured = Passport::client()->newQuery()
            ->whereNotIn('id', $ids)
            ->pluck('name', 'id');

        if ($unconfigured->isEmpty()) {
            return;
        }

        $this->components->warn('Registered clients that no configuration claims:');

        foreach ($unconfigured as $id => $name) {
            $this->components->warn("  \"{$name}\" ({$id})");
        }

        $this->components->warn('These were left alone. Deleting a client cascades to every token it issued, which is not something an environment variable should decide.');
    }

    /**
     * Mirrors the slug-to-variable rule in `config/oidc-clients.php`: upper-cased, with
     * dashes folded to underscores.
     */
    protected function environmentPrefix(string $slug): string
    {
        return 'OIDC_CLIENT_'.strtoupper(str_replace('-', '_', $slug));
    }
}
