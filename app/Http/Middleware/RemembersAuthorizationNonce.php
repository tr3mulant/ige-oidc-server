<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The auth code is not always written by the request that carried the nonce: with
 * `prompt=consent` it is written by the approve POST, and the `authRequest` Passport
 * serialises in between is a league object with no nonce concept. The session spans both.
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
