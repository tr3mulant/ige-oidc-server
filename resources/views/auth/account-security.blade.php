<x-guest-layout :heading="__('Your account security')">
    <p class="text-sm text-gray-600 dark:text-gray-400">
        {{ __('Signed in as :name (:username). These credentials admit you to every company application, so they are managed here rather than in any one of them.', ['name' => $user->name, 'username' => $user->username]) }}
    </p>

    @if (session('status') === \Laravel\Fortify\Fortify::PASSWORD_UPDATED)
        <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-200">
            {{ __('Your password has been changed.') }}
        </div>
    @endif

    {{-- ─── Two-factor ─────────────────────────────────────────────────────────── --}}

    <h2 class="mt-8 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Two-factor authentication') }}</h2>

    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
        {{ __('On since :date. It cannot be switched off — only moved to a different device.', ['date' => $user->two_factor_confirmed_at?->toFormattedDateString()]) }}
    </p>

    {{-- A link rather than the codes themselves: reading them costs a password
         confirmation, and putting them on this page would charge that toll for merely
         arriving here — which is where a fresh sign-in already lands. --}}
    <div class="mt-3">
        <a href="{{ route('account.recovery-codes') }}" class="text-sm text-gray-900 underline dark:text-gray-100">
            {{ __('View or replace your recovery codes') }}
        </a>
    </div>

    {{-- Fortify calls the endpoint behind this "disable", but enrollment is mandatory
         here: the moment it succeeds `RequiresTwoFactorEnrollment` catches the account
         and sends it straight back to enrollment. Naming the link after what actually
         happens avoids someone following it expecting to switch two-factor off. It goes
         to a confirmation step rather than submitting directly — see
         `NewDeviceController` for why that matters. --}}
    <div class="mt-2">
        <a href="{{ route('account.new-device') }}" class="text-sm text-gray-900 underline dark:text-gray-100">
            {{ __('Moving to a new phone?') }}
        </a>
    </div>

    {{-- ─── Password ───────────────────────────────────────────────────────────── --}}

    <h2 class="mt-8 border-t border-gray-200 pt-6 text-sm font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">
        {{ __('Change your password') }}
    </h2>

    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
        {{ __('One password covers every application. Changing it here changes it everywhere.') }}
    </p>

    {{-- `UpdateUserPassword` validates into the `updatePassword` bag
         (`UpdateUserPassword.php:29`). Reading the default bag here would leave a
         rejected password looking like it was accepted. --}}
    <form method="POST" action="{{ route('user-password.update') }}" class="mt-4">
        @csrf
        @method('PUT')

        <div>
            <x-input-label for="current_password" :value="__('Current password')" />
            <x-text-input
                id="current_password"
                class="mt-1 block w-full"
                type="password"
                name="current_password"
                autocomplete="current-password"
                required
            />
            <x-input-error :messages="$errors->updatePassword->get('current_password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" :value="__('New password')" />
            <x-text-input
                id="password"
                class="mt-1 block w-full"
                type="password"
                name="password"
                autocomplete="new-password"
                required
            />
            <x-input-error :messages="$errors->updatePassword->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm new password')" />
            <x-text-input
                id="password_confirmation"
                class="mt-1 block w-full"
                type="password"
                name="password_confirmation"
                autocomplete="new-password"
                required
            />
            <x-input-error :messages="$errors->updatePassword->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="mt-4 flex items-center justify-end">
            <x-primary-button>{{ __('Change password') }}</x-primary-button>
        </div>
    </form>

    <div class="mt-8 border-t border-gray-200 pt-4 dark:border-gray-700">
        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button type="submit" class="text-sm text-gray-600 underline dark:text-gray-400">
                {{ __('Sign out') }}
            </button>
        </form>
    </div>
</x-guest-layout>
