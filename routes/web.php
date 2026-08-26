<?php

use App\Http\Controllers\TwoFactorEnrollmentController;
use Illuminate\Support\Facades\Route;

/**
 * The auth surface — login, logout, password reset, email verification and the
 * two-factor challenge — is registered by Fortify, not here. Defining any of those
 * URIs in this file would silently shadow Fortify's: `RouteCollection` keys routes on
 * method + URI and the later registration wins, with no error.
 */
Route::get('/', function () {
    return view('welcome');
});

/**
 * Fortify provides the endpoints for enrolling in two-factor authentication but no
 * screen to drive them. Enrollment is mandatory here, so this is where
 * `RequiresTwoFactorEnrollment` sends anyone who has not finished it.
 */
Route::get('two-factor-enrollment', TwoFactorEnrollmentController::class)
    ->middleware('auth')
    ->name('two-factor.enroll');
