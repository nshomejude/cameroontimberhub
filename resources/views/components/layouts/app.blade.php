@props([
    'title' => null,
    'description' => null,
    'schema' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $description ?? __('messages.meta.default_description') }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <title>{{ $title ? $title . ' — ' . config('app.name') : config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,400&display=swap" rel="stylesheet">
    @fonts

    @if ($schema)
        <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col">
    <header class="sticky top-0 z-40 border-b border-sand-200 bg-sand-50/85 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-6 px-4 py-3.5">
            <a href="{{ url('/') }}" class="flex items-center gap-2.5">
                <x-brand-mark class="h-9 w-9" />
                <span class="font-display text-lg font-semibold leading-none tracking-tight text-forest-900">
                    Cameroon <span class="text-timber-700">Timber</span> Hub
                </span>
            </a>

            <nav class="hidden items-center gap-7 text-sm font-medium text-ink-soft md:flex">
                <a href="{{ route('directory') }}" class="transition hover:text-forest-700">{{ __('messages.nav.find_exporters') }}</a>
                <a href="{{ route('species.index') }}" class="transition hover:text-forest-700">{{ __('messages.nav.species') }}</a>
                <a href="#" class="transition hover:text-forest-700">{{ __('messages.nav.pricing') }}</a>
                <a href="#" class="transition hover:text-forest-700">{{ __('messages.nav.verification') }}</a>
            </nav>

            <div class="flex items-center gap-3">
                <a href="#" class="hidden text-sm font-medium text-forest-800 transition hover:text-forest-600 sm:inline">{{ __('messages.nav.list_company') }}</a>
                <a href="{{ route('rfq.create') }}" class="inline-flex items-center gap-1.5 rounded-full bg-forest-700 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-forest-800">
                    {{ __('messages.nav.request_quote') }}
                    <x-heroicon-m-arrow-right class="h-4 w-4" />
                </a>
            </div>
        </div>
    </header>

    <main class="flex-1">
        {{ $slot }}
    </main>

    <footer class="mt-24 bg-forest-900 text-sand-100">
        <div class="mx-auto max-w-6xl px-4 py-14">
            <div class="grid gap-10 md:grid-cols-3">
                <div class="md:col-span-1">
                    <div class="flex items-center gap-2.5">
                        <x-brand-mark class="h-9 w-9" />
                        <span class="font-display text-lg font-semibold text-white">Cameroon Timber Hub</span>
                    </div>
                    <p class="mt-4 max-w-xs text-sm leading-relaxed text-forest-200">
                        A verified B2B network connecting Cameroonian timber exporters with international buyers.
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-8 md:col-span-2">
                    <div>
                        <h3 class="font-display text-sm font-semibold text-timber-200">Explore</h3>
                        <ul class="mt-3 space-y-2 text-sm text-forest-200">
                            <li><a href="{{ route('directory') }}" class="transition hover:text-white">Find exporters</a></li>
                            <li><a href="{{ route('species.index') }}" class="transition hover:text-white">Timber species</a></li>
                            <li><a href="#" class="transition hover:text-white">Request a quote</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-display text-sm font-semibold text-timber-200">For exporters</h3>
                        <ul class="mt-3 space-y-2 text-sm text-forest-200">
                            <li><a href="#" class="transition hover:text-white">List your company</a></li>
                            <li><a href="#" class="transition hover:text-white">Verification</a></li>
                            <li><a href="#" class="transition hover:text-white">Pricing</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="mt-12 border-t border-forest-800 pt-6">
                <p class="max-w-3xl text-xs leading-relaxed text-forest-300">{{ __('messages.footer.disclaimer') }}</p>
                <p class="mt-3 text-xs text-forest-400">&copy; {{ date('Y') }} {{ config('app.name') }}. {{ __('messages.footer.rights') }}</p>
            </div>
        </div>
    </footer>
</body>
</html>
