@props([
    'title' => null,
    'description' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $description ?? __('messages.meta.default_description') }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <title>{{ $title ? $title . ' — ' . config('app.name') : config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col bg-stone-50 text-stone-800 antialiased">
    <header class="border-b border-stone-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
            <a href="{{ url('/') }}" class="flex items-center gap-2 font-semibold text-stone-900">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded bg-amber-600 text-white">CT</span>
                <span>{{ config('app.name') }}</span>
            </a>
            {{-- Phase B wires these to real routes; placeholders for now. --}}
            <nav class="hidden items-center gap-6 text-sm font-medium text-stone-600 md:flex">
                <a href="#" class="hover:text-amber-700">{{ __('messages.nav.find_exporters') }}</a>
                <a href="#" class="hover:text-amber-700">{{ __('messages.nav.species') }}</a>
                <a href="#" class="hover:text-amber-700">{{ __('messages.nav.pricing') }}</a>
                <a href="#" class="hover:text-amber-700">{{ __('messages.nav.verification') }}</a>
            </nav>
            <div class="flex items-center gap-3">
                <a href="#" class="hidden text-sm font-medium text-stone-600 hover:text-amber-700 sm:inline">{{ __('messages.nav.list_company') }}</a>
                <a href="#" class="rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">{{ __('messages.nav.request_quote') }}</a>
            </div>
        </div>
    </header>

    <main class="flex-1">
        {{ $slot }}
    </main>

    <footer class="mt-16 border-t border-stone-200 bg-white">
        <div class="mx-auto max-w-6xl px-4 py-8 text-sm text-stone-500">
            <p class="max-w-3xl">{{ __('messages.footer.disclaimer') }}</p>
            <p class="mt-3">&copy; {{ date('Y') }} {{ config('app.name') }}. {{ __('messages.footer.rights') }}</p>
        </div>
    </footer>
</body>
</html>
