<x-guest-layout :heading="__('Recovery codes')">
    @php
        $justRegenerated = session('status') === \Laravel\Fortify\Fortify::RECOVERY_CODES_GENERATED;
    @endphp

    @if ($justRegenerated)
        <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-200">
            {{ __('New codes generated. The previous set no longer works — replace any copy you have kept.') }}
        </div>
    @endif

    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
        {{ __('Each code signs you in once if you cannot reach your authenticator app. Store them somewhere other than the phone running it.') }}
    </p>

    <ul class="mt-4 grid grid-cols-2 gap-2 rounded-md bg-gray-100 p-4 font-mono text-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100">
        @foreach ($user->recoveryCodes() as $code)
            <li>{{ $code }}</li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}" class="mt-4">
        @csrf

        <div class="flex items-center justify-end">
            <x-primary-button>{{ __('Generate a new set') }}</x-primary-button>
        </div>
    </form>

    <div class="mt-8 border-t border-gray-200 pt-4 dark:border-gray-700">
        <a href="{{ route('account.security') }}" class="text-sm text-gray-600 underline dark:text-gray-400">
            {{ __('Back to account security') }}
        </a>
    </div>
</x-guest-layout>
