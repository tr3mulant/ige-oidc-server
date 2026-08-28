<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\Username;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Corrects the one string the whole legacy integration hangs on. The username is issued
 * as the `preferred_username` claim, which Apache turns into `REMOTE_USER`, which the
 * legacy intranet matches against `ADMIN_ML_USERS` and writes into `listed_by`.
 *
 * That makes this a heavier operation than its size suggests. Before an account has ever
 * signed in it is a harmless correction. Afterwards it renames the author of every
 * historical row that already carries the old spelling — and nothing errors, because the
 * legacy side stores the string rather than a foreign key. The old rows keep the old
 * name, the new ones get the new one, and the person's work is silently split in two.
 * Hence the warning on the way out, and hence no bulk or pattern-matching form of this
 * command.
 */
#[Signature('users:set-username
    {user : Email address or current username of the account to change}
    {username : The new short handle, which must match the legacy htpasswd spelling}')]
#[Description('Set or correct the username issued as a person\'s preferred_username claim')]
class SetUsername extends Command
{
    public function handle(): int
    {
        $identifier = (string) $this->argument('user');

        /**
         * Looked up by either column for the same reason `users:deactivate` is: whoever
         * runs this knows the person, not which of the two identifiers the roster
         * happens to hold. The `exists` check is safe here in a way it deliberately is
         * not on the login form (plan §1.5) — a terminal is not an enumeration oracle.
         */
        $user = User::where('email', $identifier)
            ->orWhere('username', $identifier)
            ->first();

        if ($user === null) {
            $this->components->error("No account matches \"{$identifier}\".");

            return self::FAILURE;
        }

        $username = (string) $this->argument('username');

        if ($user->username === $username) {
            $this->components->warn("{$user->email} already has the username \"{$username}\".");

            return self::SUCCESS;
        }

        /**
         * Validated, never folded. `users:create` rejects a capitalised username rather
         * than lowercasing it, and this has to agree: silently folding `RobinVance` to
         * `robinvance` would produce an account that works everywhere except against an
         * htpasswd file spelled some third way, which is the failure this rule exists to
         * make loud.
         */
        $validator = Validator::make(['username' => $username], [
            'username' => ['required', 'string', new Username, Rule::unique('users')->ignore($user)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $previous = $user->username;

        /**
         * Assigned rather than mass-assigned: `username` is absent from the model's
         * fillable list precisely because it is an authorization input, so `update()`
         * would drop the value and still report success.
         */
        $user->username = $username;
        $user->save();

        $this->components->info("{$user->email} is now \"{$username}\" (was \"{$previous}\").");
        $this->components->warn(
            "Legacy rows already attributed to \"{$previous}\" keep that spelling; they are not rewritten."
        );

        return self::SUCCESS;
    }
}
