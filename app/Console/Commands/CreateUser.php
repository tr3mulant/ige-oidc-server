<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\Username;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Onboarding runs through here and nowhere else. Client applications provision their
 * own local rows on first login; they never decide who is allowed to exist.
 *
 * No password argument by design: the account is created with an unguessable random
 * secret nobody records, and the user chooses their own via the emailed reset link.
 * A password passed on the command line lands in shell history.
 */
#[Signature('users:create
    {name : Display name, e.g. "Robin Vance"}
    {email : Sign-in address, and where the password link is sent}
    {username : Short handle issued as the preferred_username claim, e.g. robinvance}
    {--verified : Mark the email as already verified, skipping the verification step}')]
#[Description('Create an identity-provider account and email the user a link to set their password')]
class CreateUser extends Command
{
    public function handle(): int
    {
        /**
         * The email is folded on the way in because the login field is folded on the
         * way out: Fortify lowercases the whole credential field before looking it up
         * (`CanonicalizeUsername`), and PostgreSQL compares case-sensitively. An
         * address stored with capitals would simply never match, with no error to
         * explain why. Same reasoning applies to the roster migration (plan §1.9).
         */
        $attributes = [
            'name' => (string) $this->argument('name'),
            'email' => Str::lower(trim((string) $this->argument('email'))),
            'username' => (string) $this->argument('username'),
        ];

        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:filter', 'max:255', Rule::unique('users')],
            'username' => ['required', 'string', new Username, Rule::unique('users')],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = new User($validator->safe()->only(['name', 'email']));
        $user->username = $attributes['username'];
        $user->password = Str::random(64);
        $user->email_verified_at = $this->option('verified') ? now() : null;
        $user->save();

        $this->components->info("Created {$user->email} as \"{$user->username}\".");

        $this->sendPasswordLink($user);

        return self::SUCCESS;
    }

    /**
     * The reset notification builds its URL from the `password.reset` route. Until the
     * auth surface is in place that route does not exist and the notification throws,
     * so check first and say plainly what is left to do rather than half-creating an
     * account and erroring out.
     */
    protected function sendPasswordLink(User $user): void
    {
        if (! Route::has('password.reset')) {
            $this->components->warn('No password-set link sent: this application has no password reset route yet.');
            $this->components->warn("The account exists but has no usable password. Once the route is back, {$user->email} can set one from the sign-in page's forgot-password link.");

            return;
        }

        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_LINK_SENT) {
            $this->components->info("Password-set link emailed to {$user->email}.");

            return;
        }

        $this->components->warn("Account created, but the password link could not be sent ({$status}).");
    }
}
