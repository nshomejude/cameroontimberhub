@props([
    'title' => null,
    'description' => null,
    'schema' => null,
    'back' => null,
])

@php
    $tabs = [
        ['label' => 'Home', 'icon' => 'home', 'url' => route('home'), 'active' => request()->routeIs('home')],
        ['label' => 'Exporters', 'icon' => 'building-office-2', 'url' => route('directory'), 'active' => request()->routeIs('directory') || request()->routeIs('companies.*')],
        ['label' => 'Species', 'icon' => 'rectangle-stack', 'url' => route('species.index'), 'active' => request()->routeIs('species.*')],
        ['label' => 'Quote', 'icon' => 'chat-bubble-left-right', 'url' => route('rfq.create'), 'active' => request()->routeIs('rfq.*')],
        ['label' => 'Account', 'icon' => 'user-circle', 'url' => url('/admin/login'), 'active' => false],
    ];
    // Show a back button on detail/deep screens (else the brand mark).
    $showBack = $back ?? (request()->routeIs('companies.show') || request()->routeIs('species.show') || request()->routeIs('rfq.create') || request()->routeIs('rfq.thanks') || request()->routeIs('pricing'));
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="description" content="{{ $description ?? __('messages.meta.default_description') }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <title>{{ $title ? $title . ' — ' . config('app.name') : config('app.name') }}</title>

    {{-- PWA --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#1b3425">
    {{-- Apply the saved/system theme before first paint to avoid a flash. --}}
    <script>
        (function () {
            try {
                var s = localStorage.getItem('theme');
                var d = s ? s === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (d) {
                    document.documentElement.classList.add('dark');
                    var m = document.querySelector('meta[name=theme-color]');
                    if (m) m.setAttribute('content', '#14130f');
                }
            } catch (e) {}
        })();
    </script>
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Timber Hub">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="icon" href="/icons/icon-192.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,400&display=swap" rel="stylesheet">
    @fonts

    @if ($schema)
        <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif
    @livewireStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-shell bg-sand-50 text-ink antialiased md:pl-60 dark:bg-[#14130f] dark:text-[#f1ece1]" x-data="{ installable: false }"
      @pwa-installable.window="installable = true">

    {{-- Desktop nav rail --}}
    <aside class="fixed inset-y-0 left-0 z-40 hidden w-60 flex-col border-r border-sand-200 bg-white md:flex dark:border-[#2c2a24] dark:bg-[#1b1a16]">
        <a href="{{ route('home') }}" class="flex items-center gap-2.5 px-5 py-5">
            <x-brand-mark class="h-9 w-9" />
            <span class="font-display text-base font-semibold leading-tight text-forest-900 dark:text-sand-100">Cameroon Timber Hub</span>
        </a>
        <nav class="flex-1 space-y-1 px-3">
            @foreach ($tabs as $tab)
                <a href="{{ $tab['url'] }}" @class([
                    'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition',
                    'bg-forest-50 text-forest-800 dark:bg-forest-900/40 dark:text-forest-200' => $tab['active'],
                    'text-ink-soft hover:bg-sand-100 dark:text-[#b3ab9b] dark:hover:bg-white/5' => ! $tab['active'],
                ])>
                    <x-dynamic-component :component="($tab['active'] ? 'heroicon-s-' : 'heroicon-o-') . $tab['icon']" class="h-5 w-5" />
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </nav>
        <div class="p-4">
            <a href="{{ route('rfq.create') }}" class="flex w-full items-center justify-center gap-1.5 rounded-full bg-forest-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">
                Request a quote
            </a>
        </div>
    </aside>

    {{-- Top app bar --}}
    <header class="app-bar sticky top-0 z-30 flex items-center gap-2 border-b border-sand-200 bg-sand-50/90 px-3 backdrop-blur dark:border-[#2c2a24] dark:bg-[#14130f]/90">
        @if ($showBack)
            <a href="javascript:history.back()" data-native-ignore class="-ml-1 flex h-10 w-10 items-center justify-center rounded-full text-forest-800 active:bg-sand-200 dark:text-sand-100 dark:active:bg-white/10">
                <x-heroicon-m-chevron-left class="h-6 w-6" />
            </a>
        @else
            <span class="ml-1 md:hidden"><x-brand-mark class="h-8 w-8" /></span>
        @endif
        <h1 class="truncate font-display text-base font-semibold text-forest-900 dark:text-sand-100">{{ $title ?? 'Cameroon Timber Hub' }}</h1>
        <div class="ml-auto flex items-center gap-1">
            <button type="button"
                    x-data="{ dark: document.documentElement.classList.contains('dark') }"
                    @click="window.__toggleTheme(); dark = document.documentElement.classList.contains('dark')"
                    class="flex h-10 w-10 items-center justify-center rounded-full text-forest-800 active:bg-sand-200 dark:text-sand-100 dark:active:bg-white/10"
                    :aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'" aria-label="Toggle theme">
                <x-heroicon-o-moon class="h-5 w-5" x-show="!dark" />
                <x-heroicon-o-sun class="h-5 w-5" x-show="dark" x-cloak />
            </button>
            {{ $actions ?? '' }}
        </div>
    </header>

    {{-- Pull-to-refresh indicator --}}
    <div id="pull-indicator" class="pointer-events-none fixed inset-x-0 top-14 z-20 flex justify-center opacity-0" aria-hidden="true">
        <span class="mt-2 flex h-9 w-9 items-center justify-center rounded-full bg-white text-forest-600 shadow-md dark:bg-[#26241e] dark:text-forest-300">
            <x-heroicon-m-arrow-path class="h-5 w-5" />
        </span>
    </div>

    {{-- Routed page --}}
    <main id="app-main" class="app-page min-h-[60vh] pb-[calc(5.5rem+env(safe-area-inset-bottom))] md:pb-12">
        {{ $slot }}
    </main>

    {{-- Install-to-home-screen chip --}}
    <div x-show="installable" x-cloak x-transition
         class="fixed inset-x-3 z-40 mx-auto max-w-sm rounded-2xl bg-forest-800 p-3 text-sand-100 shadow-xl"
         style="bottom: calc(5.5rem + env(safe-area-inset-bottom))">
        <div class="flex items-center gap-3">
            <x-brand-mark class="h-9 w-9 shrink-0" />
            <p class="flex-1 text-sm">Install Timber Hub for an app-like experience.</p>
            <button @click="window.__installPwa && window.__installPwa(); installable = false" class="rounded-full bg-timber-400 px-3 py-1.5 text-xs font-semibold text-forest-950">Install</button>
            <button @click="installable = false" class="text-forest-300" aria-label="Dismiss"><x-heroicon-m-x-mark class="h-5 w-5" /></button>
        </div>
    </div>

    {{-- Bottom tab bar (mobile) --}}
    <nav class="tab-bar fixed inset-x-0 bottom-0 z-40 grid grid-cols-5 border-t border-sand-200 bg-white/95 backdrop-blur md:hidden dark:border-[#2c2a24] dark:bg-[#1b1a16]/95">
        @foreach ($tabs as $tab)
            <a href="{{ $tab['url'] }}" @class([
                'flex flex-col items-center justify-center gap-0.5 py-2 text-[11px] font-medium transition active:scale-95',
                'text-forest-700 dark:text-forest-300' => $tab['active'],
                'text-ink-soft dark:text-[#b3ab9b]' => ! $tab['active'],
            ])>
                <x-dynamic-component :component="($tab['active'] ? 'heroicon-s-' : 'heroicon-o-') . $tab['icon']" class="h-6 w-6" />
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>

    @livewireScripts
</body>
</html>
