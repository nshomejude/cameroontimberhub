@props([
    'title' => null,
    'description' => null,
    'schema' => null,
    'wide' => false,
    'image' => null,
    'noindex' => false,
    'breadcrumbs' => null,
    'type' => 'website',
])

@php
    // Primary nav, mirroring the header in the approved mockups.
    $nav = [
        ['label' => 'Marketplace', 'url' => url('/marketplace'), 'active' => request()->is('marketplace*')],
        ['label' => 'Suppliers', 'url' => route('directory'), 'active' => request()->routeIs('directory') || request()->routeIs('companies.*')],
        ['label' => 'Timber Species', 'url' => route('species.index'), 'active' => request()->routeIs('species.*')],
        ['label' => 'RFQ Center', 'url' => route('rfq.create'), 'active' => request()->routeIs('rfq.*')],
    ];

    $resources = [
        ['label' => 'Knowledge Centre', 'url' => route('knowledge.index')],
        ['label' => 'Timber Grades', 'url' => route('insights.category', 'guides')],
        ['label' => 'Export Guide', 'url' => route('insights.category', 'export')],
        ['label' => 'Market Insights', 'url' => route('insights.category', 'market')],
        ['label' => 'Blog', 'url' => route('insights.index')],
        ['label' => 'Glossary', 'url' => route('glossary.index')],
    ];

    // Deep screens swap the mobile hamburger for a back affordance.
    $showBack = request()->routeIs('companies.show')
        || request()->routeIs('species.show')
        || request()->routeIs('rfq.create')
        || request()->routeIs('rfq.thanks')
        || request()->routeIs('pricing')
        || request()->is('marketplace/*');

    // Bottom tab bar (mobile), per the mobile mockups.
    $tabs = [
        ['label' => 'Home', 'icon' => 'home', 'url' => route('home'), 'active' => request()->routeIs('home')],
        ['label' => 'Marketplace', 'icon' => 'squares-2x2', 'url' => url('/marketplace'), 'active' => request()->is('marketplace*')],
        ['label' => 'RFQ Center', 'icon' => 'document-text', 'url' => route('rfq.create'), 'active' => request()->routeIs('rfq.*')],
        ['label' => 'Suppliers', 'icon' => 'user-group', 'url' => route('directory'), 'active' => request()->routeIs('directory') || request()->routeIs('companies.*')],
        ['label' => 'Account', 'icon' => 'user', 'url' => url('/admin/login'), 'active' => false],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    {{-- Google Search Console site verification. Site-wide rather than home-page
         only: Google accepts it on any page it is asked to verify, and keeping it
         in the shared layout means it cannot go missing if the home page changes. --}}
    <meta name="google-site-verification" content="cmlfBMygwLWEyKOq8E_j526_BpAdbsaLizMEOV9-m0Y">
    @php
        $metaDescription = $description ?? __('messages.meta.default_description');
        $metaTitle = $title ? $title . ' — ' . config('app.name') : config('app.name');
        $metaImage = $image ? (str_starts_with($image, 'http') ? $image : url($image)) : url('/brand/logo-600.png');
    @endphp
    <meta name="description" content="{{ $metaDescription }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <title>{{ $metaTitle }}</title>

    {{-- Crawl directives. AI answer engines honour these alongside classic bots. --}}
    @if ($noindex)
        <meta name="robots" content="noindex, follow">
    @else
        <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
    @endif

    {{-- Open Graph / Twitter: drives link unfurls and is parsed by answer engines. --}}
    <meta property="og:type" content="{{ $type }}">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ $metaImage }}">
    <meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}_CM">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $metaTitle }}">
    <meta name="twitter:description" content="{{ $metaDescription }}">
    <meta name="twitter:image" content="{{ $metaImage }}">

    {{-- GEO: the marketplace is Cameroon-based; these are read by local search. --}}
    <meta name="geo.region" content="CM">
    <meta name="geo.placename" content="Douala, Cameroon">
    <meta name="geo.position" content="4.0511;9.7679">
    <meta name="ICBM" content="4.0511, 9.7679">

    <meta name="author" content="{{ config('app.name') }}">
    <link rel="alternate" hreflang="en" href="{{ url()->current() }}">
    <link rel="alternate" hreflang="x-default" href="{{ url()->current() }}">
    <link rel="sitemap" type="application/xml" href="{{ url('/sitemap.xml') }}">

    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#173526">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Timber Hub">
    <link rel="apple-touch-icon" href="/brand/icon-256.png">
    <link rel="icon" href="/brand/icon-96.png">

    @fonts

    {{-- AEO: site-wide Organization + WebSite graph so answer engines can
         attribute facts (name, location, contact, search endpoint) to us. --}}
    {{-- The key is written as '@'.'context' deliberately: Blade compiles a bare
         `@context` as its own directive, even inside {!! !!}, which replaced this
         key with raw PHP source and made Google discard the whole block. --}}
    <script type="application/ld+json">{!! json_encode([
        '@'.'context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'Organization',
                '@id' => url('/#organization'),
                'name' => config('app.name'),
                'url' => url('/'),
                'logo' => url('/brand/logo-600.png'),
                'description' => 'B2B marketplace connecting verified Cameroonian timber suppliers with international buyers.',
                'email' => config('contact.emails.0'),
                'telephone' => config('contact.phones.0'),
                'address' => [
                    '@type' => 'PostalAddress',
                    'addressCountry' => 'CM',
                    'addressLocality' => 'Douala',
                ],
                'areaServed' => 'Worldwide',
                'knowsAbout' => ['Timber export', 'Hardwood species', 'Iroko', 'Sapelli', 'Ayous', 'Tali', 'FLEGT compliance', 'Legal origin verification'],
            ],
            [
                '@type' => 'WebSite',
                '@id' => url('/#website'),
                'url' => url('/'),
                'name' => config('app.name'),
                'publisher' => ['@id' => url('/#organization')],
                'inLanguage' => app()->getLocale(),
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => ['@type' => 'EntryPoint', 'urlTemplate' => url('/search') . '?q={search_term_string}'],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ],
    ], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

    @if ($breadcrumbs)
        {{-- '@'.'context' for the same reason as the graph block above. --}}
        <script type="application/ld+json">{!! json_encode([
            '@'.'context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($breadcrumbs)->values()->map(fn ($crumb, $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb['label'],
                'item' => $crumb['url'] ?? null,
            ])->all(),
        ], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif

    @if ($schema)
        {{-- JSON_HEX_TAG hex-escapes angle brackets so admin-authored free text
             cannot close this script block; it stays valid JSON-LD. --}}
        <script type="application/ld+json">{!! json_encode($schema, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif
    @livewireStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white text-ink antialiased">

    {{-- ---------------- Utility bar (desktop) ---------------- --}}
    <div class="hidden bg-bark-800 text-white lg:block">
        <div class="mx-auto flex h-9 max-w-[1400px] items-center gap-6 px-6 text-[11px]">
            <ul class="flex items-center gap-5">
                @foreach (['Verified Suppliers', 'Secure B2B Transactions', 'Quality Assurance', 'Export Documentation', 'Global Buyer Network'] as $item)
                    <li class="flex items-center gap-1.5">
                        <x-heroicon-s-check-badge class="h-3.5 w-3.5 text-forest-300" />
                        <span>{{ $item }}</span>
                    </li>
                @endforeach
            </ul>
            <div class="ml-auto flex items-center gap-5">
                @if ($headerPhone = config('contact.phones.0'))
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $headerPhone) }}" class="flex items-center gap-1.5 transition hover:text-forest-200">
                        <x-heroicon-s-phone class="h-3.5 w-3.5 text-forest-300" />
                        {{ $headerPhone }}
                    </a>
                @endif
                @if ($headerEmail = config('contact.emails.0'))
                    <a href="mailto:{{ $headerEmail }}" class="flex items-center gap-1.5 transition hover:text-forest-200">
                        <x-heroicon-s-envelope class="h-3.5 w-3.5 text-forest-300" />
                        {{ $headerEmail }}
                    </a>
                @endif
                <button type="button" class="flex items-center gap-1 transition hover:text-forest-200">
                    EN <x-heroicon-m-chevron-down class="h-3 w-3" />
                </button>
            </div>
        </div>
    </div>

    {{-- ---------------- Header ---------------- --}}
    <header x-data="{ mobileMenu: false, resources: false }"
            class="sticky top-0 z-40 border-b border-sand-200 bg-white">
        <div class="mx-auto flex h-16 max-w-[1400px] items-center gap-4 px-4 lg:h-[72px] lg:px-6">

            {{-- Mobile: back on deep screens, hamburger elsewhere --}}
            @if ($showBack)
                <a href="javascript:history.back()" data-native-ignore
                   class="-ml-1 flex h-10 w-10 items-center justify-center rounded-lg text-ink lg:hidden"
                   aria-label="Go back">
                    <x-heroicon-m-chevron-left class="h-7 w-7" />
                </a>
            @else
                <button type="button" @click="mobileMenu = true"
                        class="-ml-1 flex h-10 w-10 items-center justify-center rounded-lg text-ink lg:hidden"
                        aria-label="Open menu">
                    <x-heroicon-o-bars-3 class="h-7 w-7" />
                </button>
            @endif

            <a href="{{ route('home') }}" class="flex shrink-0 items-center">
                <img src="/brand/logo-600.png" alt="Cameroon Timber Hub"
                     class="h-10 w-auto lg:h-12" width="600" height="200">
            </a>

            {{-- Desktop nav --}}
            <nav class="ml-auto hidden items-center gap-7 lg:flex">
                @foreach ($nav as $item)
                    <a href="{{ $item['url'] }}" @class([
                        'relative py-2 text-sm font-medium transition',
                        'text-forest-700 after:absolute after:inset-x-0 after:-bottom-px after:h-0.5 after:rounded-full after:bg-forest-700' => $item['active'],
                        'text-ink hover:text-forest-700' => ! $item['active'],
                    ])>{{ $item['label'] }}</a>
                @endforeach

                <div class="relative" @mouseenter="resources = true" @mouseleave="resources = false">
                    <button type="button" class="flex items-center gap-1 py-2 text-sm font-medium text-ink transition hover:text-forest-700">
                        Resources <x-heroicon-m-chevron-down class="h-4 w-4" />
                    </button>
                    <div x-show="resources" x-cloak x-transition.opacity
                         class="absolute left-0 top-full z-50 w-52 rounded-xl border border-sand-200 bg-white py-2 shadow-lg">
                        @foreach ($resources as $r)
                            <a href="{{ $r['url'] }}" class="block px-4 py-2 text-sm text-ink transition hover:bg-sand-100 hover:text-forest-700">{{ $r['label'] }}</a>
                        @endforeach
                    </div>
                </div>

                <a href="{{ route('about') }}" @class([
                    'py-2 text-sm font-medium transition',
                    'text-forest-700' => request()->routeIs('about'),
                    'text-ink hover:text-forest-700' => ! request()->routeIs('about'),
                ])>About Us</a>
                <a href="{{ route('contact') }}" @class([
                    'py-2 text-sm font-medium transition',
                    'text-forest-700' => request()->routeIs('contact'),
                    'text-ink hover:text-forest-700' => ! request()->routeIs('contact'),
                ])>Contact</a>
            </nav>

            {{-- Desktop auth actions --}}
            <div class="ml-6 hidden items-center gap-3 lg:flex">
                <a href="{{ route('login') }}"
                   class="rounded-lg border border-forest-700 px-5 py-2 text-sm font-semibold text-forest-700 transition hover:bg-forest-50">Log In</a>
                <a href="{{ route('register') }}"
                   class="rounded-lg bg-forest-700 px-5 py-2 text-sm font-semibold text-white transition hover:bg-forest-800">Join Now</a>
            </div>

            {{-- Mobile actions --}}
            <div class="ml-auto flex items-center gap-1 lg:hidden">
                <a href="{{ url('/search') }}" class="flex h-10 w-10 items-center justify-center rounded-lg text-ink" aria-label="Search">
                    <x-heroicon-o-magnifying-glass class="h-6 w-6" />
                </a>
                <a href="{{ route('login') }}" class="relative flex h-10 w-10 items-center justify-center rounded-lg text-ink" aria-label="Account">
                    <x-heroicon-o-user class="h-6 w-6" />
                </a>
            </div>
        </div>

        {{-- Mobile slide-in menu --}}
        <div x-show="mobileMenu" x-cloak class="fixed inset-0 z-50 lg:hidden">
            <div class="absolute inset-0 bg-black/40" @click="mobileMenu = false"></div>
            <div x-show="mobileMenu" x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
                 class="absolute inset-y-0 left-0 flex w-[85%] max-w-xs flex-col bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-sand-200 px-4 py-4">
                    <img src="/brand/logo-600.png" alt="Cameroon Timber Hub" class="h-9 w-auto">
                    <button type="button" @click="mobileMenu = false" class="flex h-9 w-9 items-center justify-center rounded-lg text-ink" aria-label="Close menu">
                        <x-heroicon-o-x-mark class="h-6 w-6" />
                    </button>
                </div>
                <nav class="flex-1 overflow-y-auto p-3">
                    @foreach ($nav as $item)
                        <a href="{{ $item['url'] }}" @class([
                            'block rounded-lg px-3 py-3 text-[15px] font-medium',
                            'bg-forest-50 text-forest-700' => $item['active'],
                            'text-ink' => ! $item['active'],
                        ])>{{ $item['label'] }}</a>
                    @endforeach
                    <a href="{{ route('about') }}" class="block rounded-lg px-3 py-3 text-[15px] font-medium text-ink">About Us</a>
                    <a href="{{ route('contact') }}" class="block rounded-lg px-3 py-3 text-[15px] font-medium text-ink">Contact</a>
                    <p class="px-3 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wider text-ink-soft">Resources</p>
                    @foreach ($resources as $r)
                        <a href="{{ $r['url'] }}" class="block rounded-lg px-3 py-2.5 text-[15px] text-ink">{{ $r['label'] }}</a>
                    @endforeach
                </nav>
                <div class="space-y-2 border-t border-sand-200 p-4">
                    <a href="{{ route('login') }}" class="block rounded-lg border border-forest-700 px-4 py-2.5 text-center text-sm font-semibold text-forest-700">Log In</a>
                    <a href="{{ route('register') }}" class="block rounded-lg bg-forest-700 px-4 py-2.5 text-center text-sm font-semibold text-white">Join Now</a>
                </div>
            </div>
        </div>
    </header>

    {{-- Pull-to-refresh indicator (driven by resources/js/app.js) --}}
    <div id="pull-indicator" class="pointer-events-none fixed inset-x-0 top-16 z-20 flex justify-center opacity-0" aria-hidden="true">
        <span class="mt-2 flex h-9 w-9 items-center justify-center rounded-full bg-white text-forest-700 shadow-md">
            <x-heroicon-m-arrow-path class="h-5 w-5" />
        </span>
    </div>

    {{-- ---------------- Page ---------------- --}}
    <main id="app-main" class="pb-[calc(4.5rem+env(safe-area-inset-bottom))] lg:pb-0">
        {{ $slot }}
    </main>

    {{-- Install-to-home-screen chip (PWA) --}}
    <div x-data="{ installable: false }" @pwa-installable.window="installable = true"
         x-show="installable" x-cloak x-transition
         class="fixed inset-x-3 z-40 mx-auto max-w-sm rounded-2xl bg-forest-800 p-3 text-white shadow-xl lg:hidden"
         style="bottom: calc(5rem + env(safe-area-inset-bottom))">
        <div class="flex items-center gap-3">
            <img src="/brand/icon-96.png" alt="" class="h-9 w-9 shrink-0" width="96" height="94">
            <p class="flex-1 text-sm">Install Timber Hub for an app-like experience.</p>
            <button @click="window.__installPwa && window.__installPwa(); installable = false"
                    class="rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-forest-800">Install</button>
            <button @click="installable = false" class="text-forest-200" aria-label="Dismiss">
                <x-heroicon-m-x-mark class="h-5 w-5" />
            </button>
        </div>
    </div>

    {{-- ---------------- Footer ---------------- --}}
    <footer class="bg-bark-700 text-sand-200">
        <div class="mx-auto max-w-[1400px] px-6 py-12">
            <div class="grid gap-10 lg:grid-cols-[1.6fr_repeat(4,1fr)_1.6fr]">
                <div>
                    <img src="/brand/logo-600.png" alt="Cameroon Timber Hub" class="h-11 w-auto brightness-0 invert">
                    <p class="mt-4 max-w-xs text-[13px] leading-relaxed text-sand-300/80">
                        The leading B2B marketplace connecting Cameroon's timber industry with global buyers.
                    </p>
                    <div class="mt-5 flex items-center gap-2">
                        @foreach ([
                            ['label' => 'LinkedIn', 'path' => 'M4.98 3.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM3 9h4v12H3zM9 9h3.8v1.7h.05c.53-.95 1.83-1.95 3.76-1.95 4.02 0 4.76 2.5 4.76 5.76V21h-4v-5.6c0-1.34-.03-3.07-1.9-3.07-1.9 0-2.19 1.46-2.19 2.97V21H9z'],
                            ['label' => 'Facebook', 'path' => 'M13.5 21v-8h2.7l.4-3.1h-3.1V7.9c0-.9.25-1.5 1.54-1.5h1.65V3.6c-.29-.04-1.27-.12-2.4-.12-2.38 0-4.01 1.45-4.01 4.12v2.3H7.6V13h2.68v8z'],
                            ['label' => 'X', 'path' => 'M17.53 3H20l-5.46 6.24L21 21h-5.06l-3.96-5.18L7.44 21H4.97l5.84-6.68L4 3h5.19l3.58 4.73zm-.87 16.2h1.37L8.4 4.72H6.93z'],
                            ['label' => 'YouTube', 'path' => 'M21.6 7.2c-.23-.86-.9-1.53-1.76-1.76C18.28 5 12 5 12 5s-6.28 0-7.84.44c-.86.23-1.53.9-1.76 1.76C2 8.76 2 12 2 12s0 3.24.4 4.8c.23.86.9 1.53 1.76 1.76C5.72 19 12 19 12 19s6.28 0 7.84-.44c.86-.23 1.53-.9 1.76-1.76.4-1.56.4-4.8.4-4.8s0-3.24-.4-4.8zM10 15.05v-6.1L15.2 12z'],
                        ] as $social)
                            <a href="#" aria-label="{{ $social['label'] }}"
                               class="flex h-8 w-8 items-center justify-center rounded-md bg-white/10 text-sand-200 transition hover:bg-forest-600 hover:text-white">
                                <svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4"><path d="{{ $social['path'] }}" /></svg>
                            </a>
                        @endforeach
                    </div>
                </div>

                @foreach ([
                    ['title' => 'Marketplace', 'links' => [
                        ['Browse Products', '/marketplace'],
                        ['Timber Species', '/species'],
                        ['Suppliers', '/companies'],
                        ['RFQ Center', '/request-quote'],
                    ]],
                    ['title' => 'Company', 'links' => [
                        ['About Us', '/about'],
                        ['How It Works', '/how-it-works'],
                        ['Pricing', '/pricing'],
                        ['Contact Us', '/contact'],
                    ]],
                    ['title' => 'Resources', 'links' => [
                        ['Knowledge Centre', route('knowledge.index')],
                        ['Timber Grades', route('insights.category', 'guides')],
                        ['Export Guide', route('insights.category', 'export')],
                        ['Market Insights', route('insights.category', 'market')],
                        ['Blog', route('insights.index')],
                        ['Glossary', route('glossary.index')],
                    ]],
                    ['title' => 'Support', 'links' => [
                        ['Help Center', '/help'],
                        ['Terms of Service', '/terms'],
                        ['Privacy Policy', '/privacy'],
                        ['Cookies Policy', '/cookies'],
                    ]],
                ] as $col)
                    <div>
                        <h3 class="text-sm font-semibold text-white">{{ $col['title'] }}</h3>
                        <ul class="mt-4 space-y-2.5">
                            @foreach ($col['links'] as [$label, $href])
                                <li><a href="{{ url($href) }}" class="text-[13px] text-sand-300/80 transition hover:text-white">{{ $label }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach

                <div>
                    <h3 class="text-sm font-semibold text-white">Subscribe to our newsletter</h3>
                    <p class="mt-4 text-[13px] leading-relaxed text-sand-300/80">
                        Get timber market updates, new products and industry insights.
                    </p>
                    <form action="{{ url('/newsletter') }}" method="POST" class="mt-4 flex">
                        @csrf
                        <input type="email" name="email" required placeholder="Enter your email"
                               class="w-full rounded-l-lg border-0 bg-white px-3 py-2.5 text-[13px] text-ink placeholder:text-ink-soft/60 focus:outline-none focus:ring-2 focus:ring-forest-500">
                        <button type="submit" class="shrink-0 rounded-r-lg bg-forest-600 px-4 py-2.5 text-[13px] font-semibold text-white transition hover:bg-forest-700">Subscribe</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="border-t border-white/10">
            <div class="mx-auto flex max-w-[1400px] flex-col items-center gap-2 px-6 py-4 text-[12px] text-sand-300/70 sm:flex-row">
                <p>&copy; {{ date('Y') }} Cameroon Timber Hub. All rights reserved.</p>
                <p class="sm:ml-auto">Made in Cameroon 🇨🇲</p>
            </div>
        </div>
    </footer>

    {{-- ---------------- Bottom tab bar (mobile) ---------------- --}}
    <nav class="tab-bar fixed inset-x-0 bottom-0 z-40 grid grid-cols-5 border-t border-sand-200 bg-white pb-[env(safe-area-inset-bottom)] lg:hidden">
        @foreach ($tabs as $tab)
            <a href="{{ $tab['url'] }}" @class([
                'relative flex flex-col items-center justify-center gap-1 py-2.5 text-[11px] font-medium',
                'text-forest-700 after:absolute after:inset-x-5 after:bottom-0 after:h-0.5 after:rounded-full after:bg-forest-700' => $tab['active'],
                'text-ink-soft' => ! $tab['active'],
            ])>
                <x-dynamic-component :component="($tab['active'] ? 'heroicon-s-' : 'heroicon-o-') . $tab['icon']" class="h-6 w-6" />
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>

    @livewireScripts
</body>
</html>
