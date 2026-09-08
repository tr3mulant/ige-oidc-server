---
paths:
  - 'app/Console/Commands/**'
---

# Commands

## Offboarding is two steps; users:deactivate is only the first
`users:deactivate` ends login at every application and revokes this IdP's tokens. It does NOT stop:

1. Sessions already open at client apps — bounded by each client's own session lifetime (legacy: `OIDCSessionInactivityTimeout`, 8h), not by the token TTLs. What holds a person inside a client is that client's session cookie, not a token.
2. Work that runs without a login — `tools.*`'s `runs:dispatch-scheduled` gates on its own local `is_active` column and never sees a login again.

So every client app that schedules work for a person needs its own deactivation toggle, and the command prints a warning saying so. Do not "fix" this by adding an `is_active` claim — see the rule on config/oidc-server.php.
