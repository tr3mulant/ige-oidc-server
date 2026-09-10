<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Fortify ships the endpoints for enrolling in two-factor authentication but no screen
 * to drive them. This is that screen, and because enrollment is mandatory
 * (`RequiresTwoFactorEnrollment`) it is also where a new account lands on first sign-in.
 */
class TwoFactorEnrollmentController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('auth.two-factor-enrollment', [
            'user' => $request->user(),
        ]);
    }
}
