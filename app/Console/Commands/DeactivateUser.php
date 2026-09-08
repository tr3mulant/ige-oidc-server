<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Offboarding, step one of two. Identity lives only here, so this ends every login at
 * every application — but it is not the whole procedure, and the half it misses is the
 * half nobody notices.
 *
 * Two things survive it:
 *
 * 1. Sessions already established at client applications, until their own expiry. Note
 *    that this is bounded by each client's session lifetime, not by the token TTLs — what
 *    holds a person inside a client is that client's session cookie, not a token.
 * 2. Work that runs *as a person without that person logging in*. This never stops,
 *    because the only channel that could carry the news is a login, and a deactivated
 *    person never logs in again. Today that means `tools.*`'s `runs:dispatch-scheduled`,
 *    which gates on its own local `is_active` column.
 *
 * The second is why this command prints two warnings rather than one. `is_active` is
 * deliberately not an OIDC claim: a synced one could only ever write `true`, since the
 * refusals happen before any token is issued, so it would make offboarding look
 * propagated while changing nothing. See §2.3a of the SSO implementation plan.
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
        $this->components->warn(
            'This does not stop work that runs without a login. Deactivate this person in '
            .'each client app that schedules for them — tools.* keeps its own is_active column.'
        );

        return self::SUCCESS;
    }
}
