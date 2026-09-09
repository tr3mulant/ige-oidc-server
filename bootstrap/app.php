<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ProtectsAuthorizationEndpoint;
use App\Http\Middleware\RequiresTwoFactorEnrollment;
use App\Http\Middleware\SetSecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    /**
     * No `api:` or `commands:` entry. Both skeleton files held only scaffolding —
     * `GET /api/user` and `inspire` — and this application has no API of its own: the
     * OIDC endpoints belong to `admin9/laravel-oidc-server`, which registers them
     * itself with no middleware group (`OidcServerServiceProvider::registerRoutes()`),
     * so nothing here depends on the `api` group existing. Console commands are
     * classes under `app/Console/Commands` and are discovered without a routes file.
     */
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        /**
         * Appended to the `web` group rather than named on individual routes, because
         * the route that most needs them is not ours: Passport's `/oauth/authorize` is
         * registered by `admin9/laravel-oidc-server` carrying only `web`, and it is the
         * endpoint that issues tokens for every client application.
         *
         * Order is deliberate. `SetSecurityHeaders` is first so that its response pass
         * runs last, decorating the redirects the other three produce as well as normal
         * responses. Then a deactivated account is ejected before anything else
         * considers it; then the authorization endpoint's own gate; then enrollment.
         */
        $middleware->web(append: [
            SetSecurityHeaders::class,
            EnsureUserIsActive::class,
            ProtectsAuthorizationEndpoint::class,
            RequiresTwoFactorEnrollment::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /**
         * The `api/*` prefix test that shipped with the skeleton is gone with the
         * routes file: nothing is served under that prefix. The OIDC endpoints live at
         * the paths the specification names — `/oauth/token`, `/.well-known/*` — so
         * what identifies a machine caller here is the `Accept` header, not the URL.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson(),
        );
    })->create();
