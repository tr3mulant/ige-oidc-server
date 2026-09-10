{{-- Computed before the layout tag because the heading is one of three depending on how
     far through enrollment this account is, and the layout renders it. --}}
@php
    $enrolled = $user->hasEnabledTwoFactorAuthentication();
    $awaitingConfirmation = ! is_null($user->two_factor_secret) && ! $enrolled;
    $justConfirmed = session('status') === \Laravel\Fortify\Fortify::TWO_FACTOR_AUTHENTICATION_CONFIRMED;

    $heading = match (true) {
        $justConfirmed => __('Save your recovery codes'),
        $awaitingConfirmation => __('Scan this code'),
        default => __('Set up two-factor authentication'),
    };
@endphp

<x-guest-layout :heading="$heading">
    @if ($justConfirmed)
        {{-- Shown once, on the redirect straight after confirming. Reaching these again
             later goes through Fortify's own route, which requires the password. --}}
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Two-factor authentication is on. If you lose your phone, these codes are the only way back into your account. Each one works once. Store them somewhere other than the device running your authenticator app.') }}
        </p>

        <ul class="mt-4 grid grid-cols-2 gap-2 rounded-md bg-gray-100 p-4 font-mono text-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100">
            @foreach ($user->recoveryCodes() as $code)
                <li>{{ $code }}</li>
            @endforeach
        </ul>

        <div class="mt-4 flex justify-end">
            <a
                href="{{ route('account.security') }}"
                class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold tracking-widest text-white uppercase dark:bg-gray-200 dark:text-gray-800"
            >
                {{ __('Done') }}
            </a>
        </div>
    @elseif ($awaitingConfirmation)
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('Open your authenticator app — 1Password, Google Authenticator, Authy — and scan the image below. Then enter the 6-digit code it shows, to prove it worked.') }}
        </p>

        <div class="mt-4 flex justify-center rounded-md bg-white p-4">{!! $user->twoFactorQrCodeSvg() !!}</div>

        <details class="mt-3 text-sm">
            <summary class="cursor-pointer text-gray-600 dark:text-gray-400">{{ __('Cannot scan it?') }}</summary>
            <p class="mt-2 font-mono text-xs break-all text-gray-900 dark:text-gray-100">
                {{ \Laravel\Fortify\Fortify::currentEncrypter()->decrypt($user->two_factor_secret) }}
            </p>
        </details>

        <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-4">
            @csrf

            <x-input-label for="code" :value="__('Code from your app')" />
            <x-text-input
                id="code"
                class="mt-1 block w-full text-center text-lg tracking-widest"
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

            <div class="mt-4 flex items-center justify-end">
                <x-primary-button>{{ __('Confirm') }}</x-primary-button>
            </div>
        </form>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-400">
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
