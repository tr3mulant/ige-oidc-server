<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With `prompt=consent` the code is written by the approve POST, which carries no nonce,
 * and the `authRequest` in between is a league object with no nonce concept.
 */
class RemembersAuthorizationNonce
{
    public const SESSION_KEY = 'oidc.authorize_nonce';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('passport.authorizations.authorize')) {
            return $next($request);
        }

        $nonce = $request->string('nonce')->trim()->value();

        /** Cleared rather than left, or a later authorize would inherit an old nonce. */
        $nonce === ''
            ? $request->session()->forget(self::SESSION_KEY)
            : $request->session()->put(self::SESSION_KEY, $nonce);

        return $next($request);
    }
}
