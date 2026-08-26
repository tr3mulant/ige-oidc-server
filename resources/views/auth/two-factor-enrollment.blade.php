<x-guest-layout>
    @php
        $enrolled = $user->hasEnabledTwoFactorAuthentication();
        $awaitingConfirmation = ! is_null($user->two_factor_secret) && ! $enrolled;
        $justConfirmed = session('status') === \Laravel\Fortify\Fortify::TWO_FACTOR_AUTHENTICATION_CONFIRMED;
    @endphp

    @if ($justConfirmed)
        {{-- Shown once, on the redirect straight after confirming. Reaching these again
             later goes through Fortify's own route, which requires the password. --}}
        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Save your recovery codes') }}</h1>

        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Two-factor authentication is on. If you lose your phone, these codes are the only way back into your account. Each one works once. Store them somewhere other than the device running your authenticator app.') }}
        </p>

        <ul class="mt-4 grid grid-cols-2 gap-2 rounded-md bg-gray-100 dark:bg-gray-900 p-4 font-mono text-sm text-gray-900 dark:text-gray-100">
            @foreach ($user->recoveryCodes() as $code)
                <li>{{ $code }}</li>
            @endforeach
        </ul>

        <div class="mt-4 flex justify-end">
            <a href="{{ url('/') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest">
                {{ __('Done') }}
            </a>
        </div>
    @elseif ($awaitingConfirmation)
        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Scan this code') }}</h1>

        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Open your authenticator app — 1Password, Google Authenticator, Authy — and scan the image below. Then enter the 6-digit code it shows, to prove it worked.') }}
        </p>

        <div class="mt-4 flex justify-center rounded-md bg-white p-4">
            {!! $user->twoFactorQrCodeSvg() !!}
        </div>

        <details class="mt-3 text-sm">
            <summary class="cursor-pointer text-gray-600 dark:text-gray-400">{{ __('Cannot scan it?') }}</summary>
            <p class="mt-2 break-all font-mono text-xs text-gray-900 dark:text-gray-100">
                {{ \Laravel\Fortify\Fortify::currentEncrypter()->decrypt($user->two_factor_secret) }}
            </p>
        </details>

        <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-4">
            @csrf

            <x-input-label for="code" :value="__('Code from your app')" />
            <x-text-input
                id="code"
                class="block mt-1 w-full tracking-widest text-center text-lg"
                type="text"
                name="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                autofocus
                required
            />
            {{-- `ConfirmTwoFactorAuthentication` is the one place in Fortify that uses a
                 named error bag (`ConfirmTwoFactorAuthentication.php:46`). Reading the
                 default bag here shows nothing at all on a wrong code. --}}
            <x-input-error :messages="$errors->confirmTwoFactorAuthentication->get('code')" class="mt-2" />

            <div class="flex items-center justify-end mt-4">
                <x-primary-button>{{ __('Confirm') }}</x-primary-button>
            </div>
        </form>
    @else
        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Set up two-factor authentication') }}</h1>

        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
            {{ __('This account signs you in to every company application, so it needs a second factor before it can be used. You will need an authenticator app on your phone. This takes about a minute.') }}
        </p>

        <form method="POST" action="{{ route('two-factor.enable') }}" class="mt-4">
            @csrf

            <div class="flex items-center justify-end">
                <x-primary-button>{{ __('Begin setup') }}</x-primary-button>
            </div>
        </form>
    @endif
</x-guest-layout>
