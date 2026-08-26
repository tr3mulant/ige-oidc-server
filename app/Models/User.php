<?php

namespace App\Models;

use Admin9\OidcServer\Concerns\HasOidcClaims;
use Admin9\OidcServer\Contracts\OidcUserInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * `username` is deliberately absent from the fillable list: it is an authorization
 * input on the legacy intranet, where it arrives as `REMOTE_USER` and is matched
 * against `ADMIN_ML_USERS`. Set it explicitly, never by mass assignment.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable, OidcUserInterface
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * `HasOidcClaims` is a trait, not a parent class, so a `resolveOidcClaim()` method
     * declared here would replace the trait's outright — and `parent::resolveOidcClaim()`
     * (as the package README suggests) resolves to nothing and fatals. Alias the trait's
     * implementation so the override can still fall back to it.
     */
    use HasOidcClaims {
        resolveOidcClaim as protected resolveDefaultOidcClaim;
    }

    /**
     * `is_active` is defaulted on the model as well as in the database. A column
     * default is only applied by the INSERT, so a freshly created instance would carry
     * `null` in memory until it was reloaded — and `null` is falsy, which is the one
     * value that must never be mistaken for "deactivated".
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * The `preferred_username` claim is what the legacy intranet turns into
     * `REMOTE_USER`. It is only issued when the granted scopes include it — see the
     * `profile` scope in config/oidc-server.php.
     */
    protected function resolveOidcClaim(string $claim): mixed
    {
        return match ($claim) {
            'preferred_username' => $this->username,
            default => $this->resolveDefaultOidcClaim($claim),
        };
    }
}
