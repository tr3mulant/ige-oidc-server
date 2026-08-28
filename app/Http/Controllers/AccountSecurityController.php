<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The identity provider's only self-service surface: the one screen where a person
 * manages their own credentials (plan §1.11). It covers the two things Fortify enables
 * here and ships no interface for — moving a TOTP enrollment to a new device, and
 * changing a password.
 *
 * It is not a home page and must resist becoming one. The moment it lists applications
 * or carries administrative controls, the identity provider has become an app, which is
 * the arrangement §1.10 exists to prevent.
 *
 * Enrollment itself lives in `TwoFactorEnrollmentController`: that screen is the
 * mandatory gate a new account passes through once, this one is maintenance afterwards.
 *
 * No `password.confirm` on this route. It reports status and offers actions, and every
 * action behind it — regenerating codes, clearing the enrollment, changing the password —
 * confirms the password itself. Displaying the recovery codes is the one thing that
 * exposes a secret, and that is a separate route (`RecoveryCodesController`) which does
 * confirm. Guarding the whole screen instead would mean a person who just signed in is
 * asked for their password a third time, immediately, having proved it twice.
 */
class AccountSecurityController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('auth.account-security', [
            'user' => $request->user(),
        ]);
    }
}
