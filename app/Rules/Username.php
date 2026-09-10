<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The one definition of what a username may be.
 *
 * This string is issued as the `preferred_username` claim and becomes `REMOTE_USER`
 * on the legacy intranet, where it is matched against `ADMIN_ML_USERS`. It has to
 * match the htpasswd spelling byte for byte, lowercase: years of `listed_by` values
 * and generated filenames already contain it, so a variant spelling silently detaches
 * historical rows from their author rather than erroring.
 *
 * Account creation and sign-in both validate through here. If they held separate
 * copies of the pattern, drift would produce accounts that can be created but never
 * used, or the reverse.
 *
 * A dot or an underscore may separate runs of alphanumerics, because the roster
 * contains spellings of both shapes and this rule may not be narrower than the file it
 * has to reproduce. They must be internal and single: a leading, trailing or doubled
 * separator matches nothing in the roster, and every such spelling is a typo of one
 * that is.
 */
class Username implements ValidationRule
{
    public const PATTERN = '/^[a-z0-9]+(?:[._][a-z0-9]+)*$/';

    public const MAX_LENGTH = 64;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail('The :attribute must be lowercase letters and digits, which a single dot or underscore may separate, matching the legacy htpasswd spelling exactly.');

            return;
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            $fail('The :attribute must not be longer than '.self::MAX_LENGTH.' characters.');
        }
    }
}
