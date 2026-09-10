<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\LoginRequest as FortifyLoginRequest;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /**
         * Fortify's controller type-hints its own LoginRequest, so the only way to
         * tighten the login field's validation rules is to resolve ours in its place.
         */
        $this->app->bind(FortifyLoginRequest::class, LoginRequest::class);
    }

    public function boot(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        $this->resolveCredentials();
        $this->registerViews();

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }

    /**
     * One field, two columns. `tools.*` users know their email address; legacy intranet
     * users know the short handle out of `.htpasswd`, and some of them have never had a
     * `tools.*` account at all.
     *
     * The branch is decided by `filter_var`, which is exactly what `email:filter`
     * checks in the login request's rules. Laravel's plain `email` rule is stricter RFC
     * parsing and the two disagree on inputs such as `"a b"@example.com` — validating
     * with one and routing with the other would accept a value and then query it
     * against the column it cannot possibly match.
     */
    protected function resolveCredentials(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $login = $request->string(Fortify::username())->toString();

            $user = User::firstWhere(
                filter_var($login, FILTER_VALIDATE_EMAIL) !== false ? 'email' : 'username',
                $login,
            );

            if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
                return null;
            }

            /**
             * Refused here as well as by `EnsureUserIsActive`, because that middleware
             * only ejects an established session — it never runs if the sign-in is
             * allowed to succeed in the first place. Stated plainly rather than folded
             * into "credentials do not match": whoever is typing this already holds the
             * password, so the only thing a vague message buys is a support call.
             */
            if (! $user->is_active) {
                throw ValidationException::withMessages([
                    Fortify::username() => 'This account has been deactivated. Contact an administrator.',
                ]);
            }

            return $user;
        });
    }

    protected function registerViews(): void
    {
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
    }
}
