<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts `auth` and `verified` in front of Passport's authorization endpoint, which
 * neither package does for us.
 *
 * `admin9/laravel-oidc-server` registers those three routes wrapped in
 * `config('oidc-server.routes.discovery_middleware')` (`routes/web.php:18`) — a key its
 * own documentation describes as applying to `/.well-known/*`. The result is that
 * `/oauth/authorize` carries the `web` group and nothing else, and an unauthenticated
 * request to it produces a 500 rather than a trip to the login page. The config key
 * cannot be used to fix this either: the same value is applied to the discovery
 * document in `routes/api.php:18`, and putting `auth` on discovery would break every
 * client that reads it.
 *
 * So the stack is enforced here instead, from the `web` group. This is the endpoint
 * that mints tokens for every client application; it is worth the explicitness.
 */
class ProtectsAuthorizationEndpoint
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('passport.authorizations.*')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null) {
            /** `guest()` stores the current URL as the intended one, so signing in
             * returns the browser to the authorization request it arrived with rather
             * than dropping it on the landing page. */
            return redirect()->guest(route('login'));
        }

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
