---
paths:
  - 'app/Console/Commands/**'
  - config/oidc-clients.php
---

# Commands

## Offboarding is two steps; users:deactivate is only the first
`users:deactivate` ends login at every application and revokes this IdP's tokens. It does NOT stop:

1. Sessions already open at client apps — bounded by each client's own session lifetime (legacy: `OIDCSessionInactivityTimeout`, 8h), not by the token TTLs. What holds a person inside a client is that client's session cookie, not a token.
2. Work that runs without a login — `tools.*`'s `runs:dispatch-scheduled` gates on its own local `is_active` column and never sees a login again.

So every client app that schedules work for a person needs its own deactivation toggle, and the command prints a warning saying so. Do not "fix" this by adding an `is_active` claim — see the rule on config/oidc-server.php.

## Client ids and secrets are inputs, never outputs
Never put `passport:client` in a deploy. It is not idempotent — each run adds another client with a fresh id, and the newest is not the one the apps hold a secret for, so the symptom is a login that stops working rather than an error. Its secret is also unrecoverable: `Client::secret()` hashes on assignment and keeps the plaintext only in `plainSecret`, which is populated solely on the instance that assigned it and is null for any client read back from the database.

So `clients:sync` takes the id and secret from config (`config/oidc-clients.php`, fed by `OIDC_CLIENT_{SLUG}_*`). Seeding a known secret works precisely because assignment hashes it. Verify a registered secret with `Hash::check($configured, $client->getAttributes()['secret'])` — never `plainSecret`.

The command syncs name and redirect URIs, but refuses rather than repairs on a secret mismatch (needs `--rotate-secret={slug}`), wrong grants, revocation, or an owner. Rewriting those quietly would erase the evidence with the symptom. Never prune unconfigured clients: deleting one cascades to every token it issued.

`config/oidc-clients.php` must name no host — the domain lives in `.env.production`. Closures may run while it evaluates but must not appear in the returned array, or `config:cache` (a gate in the image build) cannot export it.
