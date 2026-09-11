<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Auth\Events\Login;

/**
 * `Login` is the honest moment for `auth_time`: with two-factor enrolled, the password
 * step parks the user id under `login.id` and never establishes a session, so Fortify
 * reaches `$guard->login()` only after the challenge.
 */
class RecordAuthenticationTime
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        // `updated_at` is itself a claim, meaning the profile changed.
        $user->timestamps = false;
        $user->forceFill(['last_authenticated_at' => now()])->saveQuietly();
    }
}
