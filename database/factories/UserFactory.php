<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'username' => (string) Str::of(fake()->unique()->userName())
                ->ascii()->lower()->replaceMatches('/[^a-z0-9]/', ''),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A fully enrolled account. `two_factor_confirmed_at` matters as much as the
     * secret: with `'confirm' => true` in config/fortify.php, a secret alone leaves
     * `hasEnabledTwoFactorAuthentication()` false, and the account still gated.
     */
    public function twoFactorEnabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ABCDEFGHIJKLMNOP'),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
                json_encode(Collection::times(8, fn () => RecoveryCode::generate())->all())
            ),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
