<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The screen for an account that is *already* enrolled: read the recovery codes, replace
 * them, or move the enrollment to a new phone. Fortify ships every endpoint behind this
 * and no way to reach them — its `two-factor.recovery-codes` and `two-factor.qr-code`
 * routes answer with JSON, which is useful to a SPA and not to a person.
 *
 * Enrollment itself lives in `TwoFactorEnrollmentController`. The split follows the two
 * audiences: that screen is the mandatory gate a new account walks through once, this one
 * is maintenance for an account already past it.
 *
 * This page renders recovery codes directly, so the route carries `password.confirm` —
 * the same protection Fortify puts on the endpoints it would otherwise have to be
 * reached through (`'confirmPassword' => true` in config/fortify.php).
 */
class TwoFactorSettingsController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('auth.two-factor-settings', [
            'user' => $request->user(),
        ]);
    }
}
