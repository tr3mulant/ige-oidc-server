<x-guest-layout :heading="__('Move to a new device')">
    <p class="text-sm text-gray-600 dark:text-gray-400">
        {{ __('This removes the authenticator currently registered to your account and immediately asks you to enrol a new one. Until you finish, you will not be able to reach any company application — including this page.') }}
    </p>

    <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
        {{ __('Have the new phone in your hand, with an authenticator app installed, before you continue.') }}
    </p>

    <div class="mt-6 flex items-center justify-between">
        <a href="{{ route('account.security') }}" class="text-sm text-gray-600 underline dark:text-gray-400">
            {{ __('Cancel') }}
        </a>

        <form method="POST" action="{{ route('two-factor.disable') }}">
            @csrf
            @method('DELETE')

            <x-primary-button>{{ __('Remove it and enrol a new one') }}</x-primary-button>
        </form>
    </div>
</x-guest-layout>
