<?php

use Admin9\OidcServer\Models\OidcClient;
use App\Models\User;

return [
    /*
    |--------------------------------------------------------------------------
    | OIDC Issuer
    |--------------------------------------------------------------------------
    */
    /*
     * Trimmed here because the two readers disagree: `OidcController::discovery()` trims
     * a trailing slash and `IdTokenService` does not, so one in the environment publishes
     * an `issuer` the `iss` claim does not match. A client compares those exactly, and
     * rejects every login — with discovery, JWKS, sign-in and the token exchange all
     * looking healthy.
     */
    'issuer' => rtrim((string) env('OIDC_ISSUER', env('APP_URL')), '/'),

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model class used to look up users when generating ID tokens.
    |
    */
    /*
     * Never null, despite the package's read looking like it tolerates one:
     * `config('oidc-server.user_model', config('auth.providers.users.model'))` falls back
     * only when the key is *absent*, and it never is — the package ships its own
     * `user_model => null` and `hasConfigFile()` merges it underneath this file. Null
     * therefore reaches `null::find()` and fatals every token exchange with a 500, while
     * login and 2FA keep working — so only a client ever sees it, and only on the
     * back-channel call the browser never shows.
     */
    'user_model' => User::class,

    /*
    |--------------------------------------------------------------------------
    | Passport Auto-Configuration
    |--------------------------------------------------------------------------
    |
    | When true, the package will automatically configure Passport scopes,
    | token TTLs, response type, client model, and authorization view.
    | Set to false if you want to configure Passport yourself.
    |
    */
    'configure_passport' => true,

    /*
    |--------------------------------------------------------------------------
    | Ignore Passport Routes
    |--------------------------------------------------------------------------
    |
    | When true, the package will call Passport::ignoreRoutes() to prevent
    | Passport from registering its own routes. Set to false if you need
    | Passport's default routes alongside the OIDC routes.
    |
    */
    'ignore_passport_routes' => true,

    /*
    |--------------------------------------------------------------------------
    | Authorization View
    |--------------------------------------------------------------------------
    |
    | The Blade view used for the OAuth authorization prompt.
    | Publish and customize, or point to your own view.
    |
    */
    'authorization_view' => 'oidc-server::authorize',

    /*
    |--------------------------------------------------------------------------
    | Client Model
    |--------------------------------------------------------------------------
    |
    | The Passport Client model class. The default OidcClient skips the
    | authorization prompt for first-party clients.
    |
    */
    'client_model' => OidcClient::class,

    /*
    |--------------------------------------------------------------------------
    | Supported Scopes
    |--------------------------------------------------------------------------
    */
    'scopes' => [
        'openid' => [
            'description' => 'OpenID Connect authentication',
            'claims' => ['sub'],
        ],
        'profile' => [
            'description' => 'Access user profile information',
            // 'preferred_username' is what mod_auth_openidc maps to REMOTE_USER on the
            // legacy intranet. Claims are only issued if listed here, so removing it
            // silently produces an empty REMOTE_USER rather than an error.
            // 'nickname' and 'picture' are dropped: nothing on the User model resolves
            // them, and an unresolvable claim is just noise in every token.
            'claims' => ['name', 'preferred_username', 'updated_at'],
        ],
        'email' => [
            'description' => 'Access user email address',
            'claims' => ['email', 'email_verified'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Scopes
    |--------------------------------------------------------------------------
    */
    'default_scopes' => ['openid'],

    /*
    |--------------------------------------------------------------------------
    | Claims Resolver
    |--------------------------------------------------------------------------
    |
    | Map claim names to model attributes or callables.
    | Example: 'nickname' => 'public_name'
    | Example: 'picture' => fn($user) => $user->avatar_url
    |
    */
    'claims_resolver' => [],

    /*
    |--------------------------------------------------------------------------
    | Default Claims Map
    |--------------------------------------------------------------------------
    |
    | Map claim names to model attributes or callables for the default
    | resolution in HasOidcClaims. These are used when no claims_resolver
    | entry exists for a claim. Override to match your User model's schema.
    |
    | String values are treated as model attribute names (e.g., 'name' => 'name').
    | Computed claims live in `User::resolveOidcClaim()`.
    |
    */
    'default_claims_map' => [
        'name' => 'name',
        'email' => 'email',
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Configuration
    |--------------------------------------------------------------------------
    */
    /*
     * Chosen deliberately, not inherited. These three numbers are what bounds the
     * `is_active` kill switch: deactivating somebody stops new sign-ins immediately,
     * but a client application keeps working until its tokens run out, so the refresh
     * lifetime *is* the worst-case revocation latency.
     *
     * - id_token   300s (5 min, was 900). Consumed once, at the callback, seconds
     *              after it is issued. It has no reason to outlive that.
     * - access     900s (15 min, unchanged). Used against /oauth/userinfo.
     * - refresh  86400s (1 day, was 604800 — seven days). Seven days meant a
     *              deactivated account's client could keep minting access tokens for
     *              a week. One day is the compromise: staff still re-authenticate at
     *              most daily, and usually silently, because the browser presents an
     *              existing session at the identity provider and is redirected
     *              straight back.
     *
     * If these change, change the number in §1.7 of the SSO plan with them.
     */
    'tokens' => [
        'access_token_ttl' => (int) env('OIDC_ACCESS_TOKEN_TTL', 900),
        'refresh_token_ttl' => (int) env('OIDC_REFRESH_TOKEN_TTL', 86400),
        'id_token_ttl' => (int) env('OIDC_ID_TOKEN_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Response Types
    |--------------------------------------------------------------------------
    */
    /*
     * `token` — the implicit flow — is removed. It returns tokens in the URL fragment
     * with no client authentication and no exchange step, which is why it is
     * discouraged in OAuth 2.1. Nothing here uses it.
     */
    'response_types_supported' => [
        'code',
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Grant Types
    |--------------------------------------------------------------------------
    */
    /*
     * Only the grants the two registered clients actually use. `client_credentials`
     * carries no user identity — no `sub`, so no `REMOTE_USER` — and the device code
     * grant has no client here at all; it is additionally switched off at the source
     * in `AppServiceProvider`. Re-add either only alongside a client that needs it.
     */
    'grant_types_supported' => [
        'authorization_code',
        'refresh_token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Endpoint Auth Methods
    |--------------------------------------------------------------------------
    */
    'token_endpoint_auth_methods_supported' => [
        'client_secret_basic',
        'client_secret_post',
    ],

    /*
    |--------------------------------------------------------------------------
    | ID Token Signing Algorithms
    |--------------------------------------------------------------------------
    */
    'id_token_signing_alg_values_supported' => [
        'RS256',
    ],

    /*
    |--------------------------------------------------------------------------
    | Subject Types
    |--------------------------------------------------------------------------
    */
    'subject_types_supported' => [
        'public',
    ],

    /*
    |--------------------------------------------------------------------------
    | PKCE Code Challenge Methods
    |--------------------------------------------------------------------------
    */
    /*
     * `plain` sends the PKCE verifier unhashed, so anyone who can read the
     * authorization request can replay it — which defeats the entire point of PKCE.
     */
    'code_challenge_methods_supported' => [
        'S256',
    ],

    /*
    |--------------------------------------------------------------------------
    | Post Logout Redirect URIs
    |--------------------------------------------------------------------------
    */
    'post_logout_redirect_uris_supported' => [],

    /*
    |--------------------------------------------------------------------------
    | Routes Configuration
    |--------------------------------------------------------------------------
    |
    | Control route registration and per-group middleware.
    | - discovery_middleware: Applied to /.well-known/* endpoints
    | - token_middleware: Applied to /oauth/token, introspect, revoke, logout
    | - userinfo_middleware: Applied to /oauth/userinfo (default: auth:api,
    |   which requires a valid Passport access token)
    |
    */
    'routes' => [
        'enabled' => true,
        'discovery_middleware' => [],
        'token_middleware' => [],
        'userinfo_middleware' => ['auth:api'],
    ],
];
