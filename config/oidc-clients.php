<?php

/**
 * The relying parties this identity provider issues tokens to, and the single place
 * their ids and secrets are declared.
 *
 * Every value is read from the environment, deliberately. A redirect URI names a host,
 * and this file is application code, which the plan's 2026-09-09 decision requires to
 * stay free of any one installation's identifiers. Nothing below names a domain.
 *
 * The roster is `OIDC_CLIENTS`, a comma-separated list of slugs. Each slug names four
 * further variables, the slug upper-cased with dashes folded to underscores:
 *
 *     OIDC_CLIENTS="legacy,tools"
 *
 *     OIDC_CLIENT_LEGACY_ID="0198f3c1-....-............"
 *     OIDC_CLIENT_LEGACY_SECRET="40 or so random characters"
 *     OIDC_CLIENT_LEGACY_NAME="Legacy intranet (app.example.com)"
 *     OIDC_CLIENT_LEGACY_REDIRECT_URIS="https://app.example.com/intranet/redirect_uri"
 *     OIDC_CLIENT_LEGACY_POST_LOGOUT_REDIRECT_URIS="https://app.example.com/intranet/"
 *
 * Both URI lists are comma-separated, and both match exactly, so register the URI the
 * client actually sends and keep environments on separate clients rather than adding a
 * second URI to a production one.
 *
 * They are separate lists because they answer different questions: `_REDIRECT_URIS` is
 * where a browser returns carrying an authorization code, `_POST_LOGOUT_REDIRECT_URIS` is
 * where a person lands after signing out. An app's home page belongs in the second and
 * must not be added to the first — a redirect URI is a valid OAuth destination, which a
 * logout landing page has no reason to be.
 *
 * `_POST_LOGOUT_REDIRECT_URIS` is optional. A client that registers none gets no
 * post-logout redirect, which is what RP-Initiated Logout §2 requires of an unregistered
 * value.
 *
 * Adding an application is a slug and four variables — no code change and no migration.
 * That is the property §1.6 of the SSO plan is buying.
 *
 * The id and the secret are inputs here, never outputs. `clients:sync` seeds them and
 * never prints them, so one value reaches the IdP, the client's own configuration and
 * the secret store by construction rather than by transcription. This works because
 * `Client::secret()` hashes on assignment — which is equally the reason a registered
 * secret can never be read back out, and so the reason generating one in a pipeline is
 * the wrong shape.
 */
$slugs = array_filter(
    array_map(trim(...), explode(',', (string) env('OIDC_CLIENTS', ''))),
    static fn (string $slug): bool => $slug !== '',
);

$clients = [];

$uriList = static fn (?string $value): array => array_values(array_filter(
    array_map(trim(...), explode(',', (string) $value)),
    static fn (string $uri): bool => $uri !== '',
));

foreach ($slugs as $slug) {
    $prefix = 'OIDC_CLIENT_'.strtoupper(str_replace('-', '_', $slug));

    $clients[$slug] = [
        'id' => env($prefix.'_ID'),
        'secret' => env($prefix.'_SECRET'),
        'name' => env($prefix.'_NAME'),
        'redirect_uris' => $uriList(env($prefix.'_REDIRECT_URIS', '')),
        'post_logout_redirect_uris' => $uriList(env($prefix.'_POST_LOGOUT_REDIRECT_URIS', '')),
    ];
}

/**
 * Only plain arrays and scalars are returned. The closures above run while this file is
 * evaluated and never reach the array itself, which is what keeps `config:cache` —
 * a gate in the image build — able to export it. See commit 8a01d11 for the bug that
 * established the rule.
 */
return [
    'clients' => $clients,
];
