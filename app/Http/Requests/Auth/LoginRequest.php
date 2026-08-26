<?php

namespace App\Http\Requests\Auth;

use App\Rules\Username;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\LoginRequest as FortifyLoginRequest;

/**
 * Fortify's own login request validates only `required|string` on the credential
 * field. This narrows it to the two shapes an account can actually have, so an input
 * that could never match a row is refused without spending a database query or a
 * rate-limit slot.
 *
 * Bound over Fortify's in `App\Providers\FortifyServiceProvider::register()`.
 */
class LoginRequest extends FortifyLoginRequest
{
    /**
     * Fortify lowercases this field too, via `CanonicalizeUsername` — but that runs in
     * the login pipeline, which is *after* this request has already been validated.
     * Folding here instead means the strict lowercase-only `Username` rule below sees
     * the same value the lookup eventually will, so `RobinVance` is not rejected as
     * malformed before it ever reaches the database.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            Fortify::username() => $this->string(Fortify::username())->lower()->toString(),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            Fortify::username() => ['required', 'string', 'max:255', Rule::anyOf([
                ['email:filter'],
                [new Username],
            ])],
            'password' => ['required', 'string'],
            'remember' => 'sometimes',
        ];
    }

    /**
     * `Rule::anyOf` reports only `The login field is invalid.`, and neither branch's
     * own message reads sensibly on a field that accepts both shapes.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            Fortify::username().'.any_of' => 'Enter your email address or your username.',
        ];
    }
}
