---
paths:
  - config/oidc-server.php
---

# Config

## The IdP asserts identity, never per-app state
`is_active` is deliberately NOT an OIDC claim and must not be added to any scope or to the claims map. The IdP refuses a deactivated account before a token is ever issued (`FortifyServiceProvider::authenticateUsing()` and `EnsureUserIsActive`), so a synced claim could only ever carry `true` — it would make offboarding look propagated while changing nothing at the client, which is the fail-open shape this project avoids everywhere else.

A client needs its own active flag only for work that runs as a person WITHOUT that person logging in (today: `tools.*`'s `runs:dispatch-scheduled`). A claim cannot reach that consumer, because a claim is delivered at login and a deactivated person never logs in again.

Pinned by `tests/Feature/OidcClaimsTest.php`. Full reasoning: §2.3a of `Dowscripts/web/docs/SSO_IMPLEMENTATION_PLAN.md`.
