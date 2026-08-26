<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication is mandatory here, and Fortify on its own makes it
 * optional: it challenges a user only when `hasEnabledTwoFactorAuthentication()` is
 * true, so anyone who never enrolls is never challenged. That is a reasonable default
 * for an application. It is the wrong one for the identity provider standing in front
 * of every application in the company.
 *
 * This middleware closes that gap by refusing to let an un-enrolled account go
 * anywhere except enrollment. It runs in the `web` group, which is what puts it in
 * front of `/oauth/authorize` — the endpoint that would otherwise mint tokens for
 * every client app on behalf of someone with a password and nothing else.
 */
class RequiresTwoFactorEnrollment
{
    /**
     * Routes reachable without being enrolled. Enrollment itself obviously has to be,
     * or the redirect loops; the rest are the ways out of a half-finished state — sign
     * out, confirm a password (Fortify requires it before enabling), and verify an
     * email address.
     *
     * @var list<string>
     */
    protected array $allowedWhileUnenrolled = [
        'two-factor.enroll',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'logout',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'verification.notice',
        'verification.verify',
        'verification.send',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        if ($request->routeIs(...$this->allowedWhileUnenrolled)) {
            return $next($request);
        }

        return redirect()->route('two-factor.enroll');
    }
}
