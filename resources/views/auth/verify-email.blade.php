<x-guest-layout :heading="__('Verify your email address')">
    <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        {{ __('Before you can sign in to company applications, please confirm your email address by clicking the link we just sent you. If it has not arrived, we can send another.') }}
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 text-sm font-medium text-green-600 dark:text-green-400">
            {{ __('A new verification link has been sent to your email address.') }}
        </div>
    @endif

    <div class="mt-4 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <div>
                <x-primary-button> {{ __('Resend Verification Email') }} </x-primary-button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button
                type="submit"
                class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:outline-none dark:text-gray-400 dark:hover:text-gray-100 dark:focus:ring-offset-gray-800"
            >
                {{ __('Log Out') }}
            </button>
        </form>
    </div>
</x-guest-layout>
