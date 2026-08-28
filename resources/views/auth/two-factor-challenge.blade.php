<x-guest-layout :heading="__('Two-factor authentication')">
    <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        {{ __('Open your authenticator app and enter the 6-digit code it is showing.') }}
    </div>

    {{--
        Fortify accepts either field and validates both as nullable
        (TwoFactorLoginRequest::rules()), so one form carries both. No JavaScript:
        `details` collapses the recovery path without needing any.
    --}}
    <form method="POST" action="{{ route('two-factor.login.store') }}">
        @csrf

        <div>
            <x-input-label for="code" :value="__('Authentication Code')" />
            <x-text-input
                id="code"
                class="mt-1 block w-full text-center text-lg tracking-widest"
                type="text"
                name="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                autofocus
            />
            <x-input-error :messages="$errors->get('code')" class="mt-2" />
        </div>

        <details class="mt-4 text-sm">
            <summary class="cursor-pointer text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100">
                {{ __('Lost your device? Use a recovery code') }}
            </summary>

            <div class="mt-3">
                <x-input-label for="recovery_code" :value="__('Recovery Code')" />
                <x-text-input
                    id="recovery_code"
                    class="mt-1 block w-full"
                    type="text"
                    name="recovery_code"
                    autocomplete="one-time-code"
                />
                <x-input-error :messages="$errors->get('recovery_code')" class="mt-2" />

                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Each recovery code works once. Regenerate them afterwards.') }}
                </p>
            </div>
        </details>

        <div class="mt-4 flex items-center justify-end">
            <x-primary-button> {{ __('Verify') }} </x-primary-button>
        </div>
    </form>
</x-guest-layout>
