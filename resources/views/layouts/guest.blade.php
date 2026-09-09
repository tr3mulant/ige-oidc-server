<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />

    {{-- Derived from the page's own heading so the two cannot drift, and a fixed
             name rather than `config('app.name')` — an unset APP_NAME would otherwise
             put "Laravel" in the title bar of the company's authentication host. --}}
    <title>{{ $heading }} - IGE Account</title>

    {{-- Most of these pages have nobody signed in, so there is no stored theme
             preference to read. Tailwind's dark: utilities follow the OS setting on
             their own. --}}
    <meta name="color-scheme" content="light dark" />

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any" />
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}" />

    @fonts

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased">
    <div class="flex min-h-screen flex-col items-center bg-gray-100 pt-6 text-gray-900 sm:justify-center sm:pt-0 dark:bg-gray-900 dark:text-gray-100">
        {{-- The page's one and only h1. Pages used to carry their own, which is why
                 this is a prop: a heading here *and* in the page rendered two of them,
                 and titled every page after whichever one sat in the layout. --}}
        <h1 class="text-xl font-semibold tracking-tight">{{ $heading }}</h1>

        @if ($subheading)
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $subheading }}</p>
        @endif

        <div class="mt-6 w-full overflow-hidden bg-white px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg dark:bg-gray-800">
            {{ $slot }}
        </div>
    </div>
</body>
</html>
