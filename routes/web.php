<?php

use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\NewDeviceController;
use App\Http\Controllers\RecoveryCodesController;
use App\Http\Controllers\RootRedirectController;
use App\Http\Controllers\TwoFactorEnrollmentController;
use Illuminate\Support\Facades\Route;

/**
 * The auth surface — login, logout, password reset, email verification and the
 * two-factor challenge — is registered by Fortify, not here. Defining any of those
 * URIs in this file would silently shadow Fortify's: `RouteCollection` keys routes on
 * method + URI and the later registration wins, with no error.
 */

/**
 * No welcome page, and no home page of any kind: this host authenticates people and
 * hands them back to the application that sent them (plan §1.11).
 */
Route::get('/', RootRedirectController::class)->name('root');

/**
 * Fortify provides the endpoints for enrolling in two-factor authentication but no
 * screen to drive them. Enrollment is mandatory here, so this is where
 * `RequiresTwoFactorEnrollment` sends anyone who has not finished it.
 */
Route::get('two-factor-enrollment', TwoFactorEnrollmentController::class)
    ->middleware('auth')
    ->name('two-factor.enroll');

/**
 * Self-service credential management for an account already enrolled, and the
 * destination for anyone who reaches this host without one — see `fortify.home`.
 */
Route::get('account-security', AccountSecurityController::class)
    ->middleware('auth')
    ->name('account.security');

/**
 * Split from the screen above so that reading the codes costs a password confirmation
 * and merely visiting the screen does not. Landing on `account-security` straight after
 * a sign-in would otherwise demand the password a third time in a row.
 */
Route::get('account-security/recovery-codes', RecoveryCodesController::class)
    ->middleware(['auth', 'password.confirm'])
    ->name('account.recovery-codes');

/**
 * Confirmation step in front of Fortify's `two-factor.disable`. Reached by GET so that
 * the password confirmation it shares with that endpoint lands the person *here*, ready
 * to act, rather than back where they started having apparently achieved nothing.
 */
Route::get('account-security/new-device', NewDeviceController::class)
    ->middleware(['auth', 'password.confirm'])
    ->name('account.new-device');
