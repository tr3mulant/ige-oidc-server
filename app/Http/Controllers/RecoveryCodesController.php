<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The recovery codes, on their own route because they are the one secret this
 * application will render back to a signed-in person — so this is the one screen that
 * carries `password.confirm` (plan §1.11).
 *
 * That protection is the reason the codes are not simply printed on the account-security
 * screen. Password confirmation exists for the borrowed session: someone at an unlocked
 * laptop within `auth.password_timeout` of a sign-in. Marking the password confirmed at
 * login would make the prompt disappear and take the protection with it.
 *
 * Fortify has a `two-factor.recovery-codes` route of its own and it is not this one — it
 * answers with JSON, which serves a SPA and not a person.
 */
class RecoveryCodesController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('auth.recovery-codes', [
            'user' => $request->user(),
        ]);
    }
}
