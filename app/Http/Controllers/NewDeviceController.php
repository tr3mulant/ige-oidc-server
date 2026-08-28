<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The confirmation step before an enrollment is cleared and moved to another phone.
 *
 * It exists to put the password confirmation somewhere useful. Fortify guards
 * `two-factor.disable` with `password.confirm`, and for a non-GET request
 * `Redirector::guest()` records `previous()` as the intended URL rather than the
 * unrepeatable DELETE — so submitting that form straight from the account screen sends
 * the person to confirm their password and then returns them to where they started, with
 * nothing appearing to have happened. They have to click it a second time.
 *
 * Reaching the same guard through a GET makes the interstitial itself the intended URL,
 * so confirming lands here and the action is one click away. A destructive step that
 * locks the account out of every application until a new phone is enrolled is worth
 * pausing on regardless.
 */
class NewDeviceController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('auth.new-device', [
            'user' => $request->user(),
        ]);
    }
}
