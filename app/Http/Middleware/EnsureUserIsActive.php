<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign out a deactivated account mid-session, so revocation does not wait for the
 * session to expire.
 *
 * This only governs sessions at the identity provider. A browser session already
 * established at a client application survives until its own expiry — that latency is
 * bounded by the token TTLs, not by this middleware. What this does guarantee is that
 * a deactivated account cannot complete another authorization and collect fresh
 * tokens.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'login' => 'This account has been deactivated. Contact an administrator.',
            ]);
        }

        return $next($request);
    }
}
