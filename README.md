# ige-oidc-server

OIDC Server for Irongate Enterprises.

## Registering a relying party

A client is declared in the environment and registered by `php artisan clients:sync`.
The command is safe to re-run, so a deploy can call it on every push: it creates a client
that is absent and verifies one that already exists.

Declaring a client takes **two edits, both required**:

1. Add a slug of your choosing to `OIDC_CLIENTS`, comma-separated for several.
2. Add all four `OIDC_CLIENT_<SLUG>_*` variables for that slug, slug uppercased.

A slug listed with no variables behind it fails validation. Variables whose slug is not
listed are never read. An empty `OIDC_CLIENTS` registers nothing and the command no-ops.

### Generating the id and the secret

Both are values you generate once, before the first sync, and store in the password
manager:

```bash
uuidgen              # the _ID
openssl rand -hex 20 # the _SECRET, 40 characters
```

**Save the secret before you run the command.** `Client::secret()` hashes on assignment,
so from that moment the secret cannot be read back out of the database — the plaintext
exists only in the memory of the process that set it. The same value has to reach the
client application and, for the legacy intranet, Apache's `OIDCClientSecret`; recovering
a lost one means reissuing the client across all three at once.

This is also why the id and secret are _inputs_ rather than command output. A bare
`passport:client` in a deploy is not idempotent — each run adds another client with a
fresh id, and the newest is not the one the applications hold a secret for, so the
symptom is a login that quietly stops working rather than an error.

### Worked example

Two clients means nine lines:

```dotenv
OIDC_CLIENTS=legacy,tools

OIDC_CLIENT_LEGACY_ID=9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d
OIDC_CLIENT_LEGACY_SECRET=8f3a1c7e94b06d25af18e3c70b9d4a62f5138ec0
OIDC_CLIENT_LEGACY_NAME="Legacy intranet (app.example.com)"
OIDC_CLIENT_LEGACY_REDIRECT_URIS=https://app.example.com/intranet/redirect_uri

OIDC_CLIENT_TOOLS_ID=3f2504e0-4f89-41d3-9a0c-0305e82c3301
OIDC_CLIENT_TOOLS_SECRET=c04b7d19e6a35f82041de9b7c3628af5019d4e7b
OIDC_CLIENT_TOOLS_NAME="Tools (tools.example.com)"
OIDC_CLIENT_TOOLS_REDIRECT_URIS=https://tools.example.com/auth/callback
```

Quote `_NAME` when it contains spaces.

### Redirect URIs

`_REDIRECT_URIS` is matched **exactly** — no trailing-slash tolerance, no wildcards, no
path prefixes. Give one URI per client, and give each environment its own client. Never
add a staging or `.test` URI to the production client.

The variable accepts a comma-separated list only because Passport stores redirect URIs as
an array. The one time that is useful is moving a client's callback path: register both
for the length of the cutover, then drop the old one and re-run the command.

### What the command will and will not change

| Situation                                                         | Result                                      |
| ----------------------------------------------------------------- | ------------------------------------------- |
| Client absent                                                     | Registered                                  |
| `_NAME` or `_REDIRECT_URIS` differ                                | Updated to match the environment            |
| `_SECRET` differs                                                 | **Refused.** Needs `--rotate-secret=<slug>` |
| Client has grants beyond `authorization_code` and `refresh_token` | **Refused**, never repaired                 |
| Client is revoked, or has an owner                                | **Refused**, never repaired                 |
| Registered client no configuration claims                         | Reported, left alone                        |

Any of those refusals exits non-zero, so a deploy fails rather than reporting success over
a login that no longer works.

Rotation is deliberate because it puts the IdP, the client application and the vhost out
of agreement until all three carry the new value. Without the flag a mismatch is a hard
failure that changes nothing — a mismatch is at least as likely to mean the environment
lost the right value as that somebody intended a new one.

Wrong grants, revocation and ownership are refused rather than corrected because each is a
security event rather than drift: a client that has acquired a password grant, or an owner
that puts a consent screen in front of every sign-in, is something to investigate, and
rewriting it quietly would erase the evidence along with the symptom.
