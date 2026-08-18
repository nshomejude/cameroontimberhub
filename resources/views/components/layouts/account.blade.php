@props([
    'title' => null,
    'description' => null,
    'heading' => null,
    'subheading' => null,
])

@php
    /**
     * Buyer account shell.
     *
     * A separate layout from `layouts.app` on purpose: the approved buyer
     * dashboard mockups are an application shell (dark persistent sidebar +
     * topbar on desktop, drawer + bottom tab bar on mobile), not the public
     * marketing chrome. `layouts.app` is approved and off-limits, so this is a
     * sibling rather than a restructure.
     *
     * Every nav entry below points at a route that actually exists. The
     * mockup's Contracts / Shipments / Payments / Documents / Reports / Saved
     * Searches / Team / KYC items have no backing feature or column, so they
     * are omitted rather than shipped as dead links.
     */
    $user = auth()->user();

    // Real unread count (derived from last_read_at), or null when there is
    // nothing to show — never a placeholder badge.
    $unreadMessages = $user ? (app(\App\Services\MessagingService::class)->totalUnread($user) ?: null) : null;

    $groups = [
        [
            'label' => 'Marketplace',
            'items' => [
                ['label' => 'Dashboard', 'icon' => 'squares-2x2', 'url' => route('account.index'), 'active' => request()->routeIs('account.index')],
                ['label' => 'Browse Timber', 'icon' => 'cube', 'url' => url('/marketplace'), 'active' => request()->is('marketplace*')],
                ['label' => 'Suppliers', 'icon' => 'user-group', 'url' => route('directory'), 'active' => request()->routeIs('directory')],
                ['label' => 'Timber Species', 'icon' => 'sparkles', 'url' => route('species.index'), 'active' => request()->routeIs('species.*')],
            ],
        ],
        [
            'label' => 'My activity',
            'items' => [
                ['label' => 'Messages', 'icon' => 'chat-bubble-left-right', 'url' => route('account.messages'), 'active' => request()->routeIs('account.messages*'), 'badge' => $unreadMessages],
                ['label' => 'RFQ Center', 'icon' => 'document-text', 'url' => route('account.rfqs'), 'active' => request()->routeIs('account.rfqs')],
                ['label' => 'Quotes', 'icon' => 'tag', 'url' => route('account.quotes'), 'active' => request()->routeIs('account.quotes')],
                ['label' => 'Orders', 'icon' => 'clipboard-document-check', 'url' => route('account.orders'), 'active' => request()->routeIs('account.orders')],
                ['label' => 'Receipts', 'icon' => 'receipt-percent', 'url' => route('account.receipts'), 'active' => request()->routeIs('account.receipts')],
            ],
        ],
        [
            'label' => 'Support',
            'items' => [
                ['label' => 'Verify a receipt', 'icon' => 'shield-check', 'url' => route('receipts.verify'), 'active' => request()->routeIs('receipts.verify')],
                ['label' => 'Help Center', 'icon' => 'question-mark-circle', 'url' => route('contact'), 'active' => request()->routeIs('contact')],
            ],
        ],
    ];

    // Bottom tab bar (mobile), mirroring the mockup's four-tab pattern but with
    // real destinations only.
    $tabs = [
        ['label' => 'Dashboard', 'icon' => 'squares-2x2', 'url' => route('account.index'), 'active' => request()->routeIs('account.index')],
        ['label' => 'Requests', 'icon' => 'document-text', 'url' => route('account.rfqs'), 'active' => request()->routeIs('account.rfqs')],
        ['label' => 'Messages', 'icon' => 'chat-bubble-left-right', 'url' => route('account.messages'), 'active' => request()->routeIs('account.messages*'), 'badge' => $unreadMessages],
        ['label' => 'Quotes', 'icon' => 'tag', 'url' => route('account.quotes'), 'active' => request()->routeIs('account.quotes')],
        ['label' => 'Orders', 'icon' => 'clipboard-document-check', 'url' => route('account.orders'), 'active' => request()->routeIs('account.orders')],
    ];

    $initials = collect(explode(' ', trim((string) $user?->name)))
        ->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('') ?: 'B';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ? $title.' — '.config('app.name') : config('app.name') }}</title>
    <meta name="description" content="{{ $description ?? 'Your buyer account.' }}">
    {{-- Private records: never indexed, and no canonical/OG that could leak a URL. --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#032719">
    <link rel="icon" href="/brand/icon-96.png">
    <link rel="apple-touch-icon" href="/brand/icon-256.png">
    @fonts
    @livewireStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-sand-100 text-ink antialiased">

<div x-data="{ drawer: false }" class="min-h-screen lg:flex">

    {{-- ============================ SIDEBAR ============================ --}}
    {{-- Desktop: always present. Mobile: the same markup, slid in as a drawer. --}}
    <div x-cloak x-show="drawer"
         @click="drawer = false"
         class="fixed inset-0 z-40 bg-black/50 lg:hidden"
         aria-hidden="true"></div>

    <aside x-cloak
           :class="drawer ? 'translate-x-0' : '-translate-x-full'"
           class="fixed inset-y-0 left-0 z-50 flex w-[17rem] shrink-0 flex-col overflow-y-auto bg-[#032719] text-sand-100 transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:w-[15rem] lg:translate-x-0"
           aria-label="Account navigation">

        <div class="flex items-start gap-3 px-5 pt-5 pb-4">
            <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-2.5">
                <img src="{{ asset('brand/icon-96.png') }}" alt="" class="h-9 w-9 shrink-0 rounded-full">
                <span class="min-w-0">
                    <span class="block font-display text-[0.9375rem] font-bold leading-tight tracking-tight text-white">Cameroon<br>Timber Hub</span>
                    <span class="mt-0.5 block text-[0.5625rem] font-semibold uppercase tracking-[0.18em] text-timber-300">Connect · Trade · Grow</span>
                </span>
            </a>
            <button type="button" @click="drawer = false"
                    class="ml-auto -mr-1 flex h-9 w-9 items-center justify-center rounded-full text-sand-300 lg:hidden"
                    aria-label="Close menu">
                <x-heroicon-m-x-mark class="h-6 w-6" />
            </button>
        </div>

        {{-- Identity block (mobile mockup shows it inside the drawer). --}}
        <div class="mx-5 flex items-center gap-3 border-y border-white/10 py-4 lg:hidden">
            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-forest-600 text-[0.8125rem] font-bold text-white">{{ $initials }}</span>
            <span class="min-w-0">
                <span class="block truncate text-[0.9375rem] font-semibold text-white">{{ $user?->name }}</span>
                <span class="block text-[0.75rem] text-sand-400">Buyer account</span>
            </span>
        </div>

        <nav class="mt-2 flex-1 px-3 pb-4">
            @foreach ($groups as $group)
                <p class="px-3 pb-1.5 pt-4 text-[0.625rem] font-bold uppercase tracking-[0.16em] text-timber-300">{{ $group['label'] }}</p>
                <ul class="space-y-0.5">
                    @foreach ($group['items'] as $item)
                        <li>
                            <a href="{{ $item['url'] }}"
                               @if ($item['active']) aria-current="page" @endif
                               @class([
                                   'flex items-center gap-3 rounded-xl px-3 py-2.5 text-[0.875rem] font-medium transition',
                                   'bg-gradient-to-r from-[#6b3a16] to-[#8a5a30] text-white shadow-sm' => $item['active'],
                                   'text-sand-200/85 hover:bg-white/5 hover:text-white' => ! $item['active'],
                               ])>
                                <x-dynamic-component :component="'heroicon-o-'.$item['icon']" class="h-5 w-5 shrink-0" />
                                <span class="truncate">{{ $item['label'] }}</span>
                                @if (($item['badge'] ?? null))
                                    <span class="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-forest-600 px-1.5 text-[0.6875rem] font-bold text-white">{{ $item['badge'] }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endforeach

            {{-- Promo card — a real CTA into the RFQ wizard. --}}
            <div class="mt-6 rounded-2xl bg-[#053e23] p-4 ring-1 ring-white/10">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-timber-700/60 text-timber-200">
                        <x-heroicon-o-cube class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[0.875rem] font-semibold text-white">Need bulk timber?</p>
                        <p class="mt-1 text-[0.75rem] leading-relaxed text-sand-300/90">Post an RFQ and get competitive quotes from verified suppliers.</p>
                    </div>
                </div>
                <a href="{{ route('rfq.create') }}"
                   class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-white px-4 py-2.5 text-[0.8125rem] font-bold text-forest-800 transition hover:bg-sand-200">
                    Post an RFQ <x-heroicon-m-arrow-right class="h-4 w-4" />
                </a>
            </div>

            <form method="POST" action="{{ route('logout') }}" class="mt-4">
                @csrf
                <button type="submit"
                        class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-[0.875rem] font-medium text-sand-200/85 transition hover:bg-white/5 hover:text-white">
                    <x-heroicon-o-arrow-right-on-rectangle class="h-5 w-5 shrink-0" />
                    Log out
                </button>
            </form>
        </nav>
    </aside>

    {{-- ============================== MAIN ============================== --}}
    <div class="flex min-w-0 flex-1 flex-col">

        {{-- Topbar --}}
        <header class="app-bar sticky top-0 z-30 border-b border-sand-200 bg-white">
            <div class="flex min-h-14 items-center gap-3 px-4 lg:h-[72px] lg:px-6">
                <button type="button" @click="drawer = true"
                        class="-ml-1 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-ink transition hover:bg-sand-100"
                        aria-label="Open menu">
                    <x-heroicon-m-bars-3 class="h-6 w-6" />
                </button>

                <div class="min-w-0 flex-1">
                    <h1 class="truncate font-display text-[1.0625rem] font-bold text-forest-950 lg:text-2xl">
                        {{ $heading ?? $title ?? 'Dashboard' }}
                    </h1>
                    @if ($subheading)
                        <p class="hidden truncate text-[0.8125rem] text-ink-soft lg:block">{{ $subheading }}</p>
                    @endif
                </div>

                {{-- Real search: same endpoint as the public header. --}}
                <form method="GET" action="{{ route('search') }}" class="hidden max-w-md flex-1 lg:flex">
                    <label for="account-search" class="sr-only">Search timber species, products, suppliers</label>
                    <div class="flex w-full items-center rounded-xl border border-sand-300 bg-sand-50 focus-within:border-forest-500">
                        <input id="account-search" type="search" name="q" value="{{ request('q') }}"
                               placeholder="Search timber species, products, suppliers…"
                               class="min-w-0 flex-1 bg-transparent px-4 py-2.5 text-[0.875rem] text-ink outline-none placeholder:text-ink-soft">
                        <button type="submit" class="m-1 flex h-9 w-10 items-center justify-center rounded-lg bg-forest-700 text-white transition hover:bg-forest-800" aria-label="Search">
                            <x-heroicon-m-magnifying-glass class="h-4 w-4" />
                        </button>
                    </div>
                </form>

                <div class="ml-auto flex shrink-0 items-center gap-3 lg:ml-0">
                    <div class="hidden text-right lg:block">
                        <p class="text-[0.875rem] font-semibold leading-tight text-ink">{{ $user?->name }}</p>
                        <p class="text-[0.75rem] text-ink-soft">Buyer</p>
                    </div>
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-forest-700 text-[0.8125rem] font-bold text-white">{{ $initials }}</span>
                </div>
            </div>
        </header>

        <main class="flex-1 px-4 pb-28 pt-5 lg:px-6 lg:pb-10 lg:pt-6">
            {{ $slot }}
        </main>

        <footer class="hidden border-t border-sand-200 bg-white px-6 py-4 text-[0.75rem] text-ink-soft lg:block">
            <div class="flex flex-wrap items-center gap-4">
                <span>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</span>
                <span class="ml-auto flex gap-4">
                    <a href="{{ url('/privacy-policy') }}" class="transition hover:text-forest-700">Privacy Policy</a>
                    <a href="{{ url('/terms') }}" class="transition hover:text-forest-700">Terms of Use</a>
                    <a href="{{ route('contact') }}" class="transition hover:text-forest-700">Help Center</a>
                </span>
            </div>
        </footer>
    </div>
</div>

{{-- Bottom tab bar (mobile only), per the mobile mockup. --}}
<nav class="tab-bar fixed inset-x-0 bottom-0 z-30 grid grid-cols-5 border-t border-sand-200 bg-white lg:hidden"
     aria-label="Account sections">
    @foreach ($tabs as $tab)
        <a href="{{ $tab['url'] }}"
           @if ($tab['active']) aria-current="page" @endif
           @class([
               'flex flex-col items-center gap-1 py-2.5 text-[0.6875rem] font-semibold',
               'text-forest-700' => $tab['active'],
               'text-ink-soft' => ! $tab['active'],
           ])>
            <span class="relative">
                <x-dynamic-component :component="'heroicon-'.($tab['active'] ? 's' : 'o').'-'.$tab['icon']" class="h-6 w-6" />
                @if (($tab['badge'] ?? null))
                    <span class="absolute -right-2.5 -top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-forest-600 px-1 text-[0.625rem] font-bold text-white">{{ $tab['badge'] }}</span>
                @endif
            </span>
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>

@livewireScripts
</body>
</html>
