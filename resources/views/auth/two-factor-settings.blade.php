<x-guest-layout>
    @php
        $codes = $user->recoveryCodes();
        $justRegenerated = session('status') === \Laravel\Fortify\Fortify::RECOVERY_CODES_GENERATED;
    @endphp

    <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Two-factor authentication') }}</h1>

    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
        {{ __('On since :date. This account signs you in to every company application, so it cannot be turned off — only moved to a different device.', ['date' => $user->two_factor_confirmed_at?->toFormattedDateString()]) }}
    </p>

    @if ($justRegenerated)
        <div class="mt-4 rounded-md bg-green-50 dark:bg-green-900/30 p-3 text-sm text-green-800 dark:text-green-200">
            {{ __('New recovery codes generated. The previous set no longer works.') }}
        </div>
    @endif

    <h2 class="mt-6 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Recovery codes') }}</h2>

    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
        {{ __('Each code signs you in once if you cannot reach your authenticator app. Store them somewhere other than the phone running it.') }}
    </p>

    <ul class="mt-3 grid grid-cols-2 gap-2 rounded-md bg-gray-100 dark:bg-gray-900 p-4 font-mono text-sm text-gray-900 dark:text-gray-100">
        @foreach ($codes as $code)
            <li>{{ $code }}</li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}" class="mt-3">
        @csrf

        <div class="flex items-center justify-end">
            <x-primary-button>{{ __('Generate a new set') }}</x-primary-button>
        </div>
    </form>

    <h2 class="mt-8 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Moving to a new phone') }}</h2>

    {{-- Fortify calls this endpoint "disable", but enrollment is mandatory here: the
         moment it succeeds `RequiresTwoFactorEnrollment` catches the account and sends it
         straight back to enrollment. Naming the button after what actually happens avoids
         someone clicking it expecting to switch two-factor off. --}}
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
        {{ __('This clears the current enrollment and asks you to scan a fresh code immediately. Do it while you have the new phone in hand — you will not be able to reach anything else until it is done.') }}
    </p>

    <form method="POST" action="{{ route('two-factor.disable') }}" class="mt-3">
        @csrf
        @method('DELETE')

        <div class="flex items-center justify-end">
            <x-primary-button>{{ __('Start over on a new device') }}</x-primary-button>
        </div>
    </form>

    <div class="mt-8 border-t border-gray-200 dark:border-gray-700 pt-4">
        <a href="{{ url('/') }}" class="text-sm text-gray-600 dark:text-gray-400 underline">
            {{ __('Back') }}
        </a>
    </div>
</x-guest-layout>
