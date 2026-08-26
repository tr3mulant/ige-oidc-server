<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Offboarding, in one place. Because identity lives only here, this is the whole
 * procedure: no client application has an account to disable, and none gets a say.
 *
 * What it does not do is end sessions already established at client applications —
 * those survive until their own expiry, which is what the token TTLs bound. What it
 * guarantees is that this person completes no further authorization and receives no
 * further tokens.
 */
#[Signature('users:deactivate
    {user : Email address or username}
    {--restore : Reactivate the account instead of deactivating it}')]
#[Description('Revoke a person\'s access to every application, or restore it')]
class DeactivateUser extends Command
{
    public function handle(): int
    {
        $identifier = (string) $this->argument('user');

        $user = User::where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();

        if ($user === null) {
            $this->components->error("No account matches \"{$identifier}\".");

            return self::FAILURE;
        }

        $restoring = (bool) $this->option('restore');

        if ($user->is_active === $restoring) {
            $this->components->warn(
                "{$user->email} is already ".($restoring ? 'active' : 'deactivated').'.'
            );

            return self::SUCCESS;
        }

        /**
         * Assigned rather than mass-assigned. `is_active` is deliberately absent from
         * the model's fillable list — it is an authorization input, in the same
         * category as `username` — so `update(['is_active' => …])` would discard the
         * value and still return true. A kill switch that reports success without
         * doing anything is worse than one that errors.
         */
        $user->is_active = $restoring;
        $user->save();

        if ($restoring) {
            $this->components->info("Restored access for {$user->email}.");

            return self::SUCCESS;
        }

        /**
         * Existing tokens would otherwise stay valid until they expire. Revoking them
         * does not reach a client's own session cookie, but it does stop anything that
         * re-presents a token here — UserInfo among them.
         */
        $revoked = $user->tokens()->update(['revoked' => true]);

        $this->components->info("Deactivated {$user->email}; revoked {$revoked} token(s).");
        $this->components->warn('Sessions already open at client applications last until they expire.');

        return self::SUCCESS;
    }
}
