@php
    use App\Enums\PriceUnit;

    /** Resolve a mockup-extracted image, falling back to a neutral timber photo. */
    $img = function (?string $path): string {
        if ($path && is_file(public_path('img/'.ltrim($path, '/')))) {
            return asset('img/'.ltrim($path, '/'));
        }

        return asset('img/products/iroko-sawn-timber.jpg');
    };

    $speciesImg = function (string $slug): string {
        return is_file(public_path("img/species/{$slug}.png"))
            ? asset("img/species/{$slug}.png")
            : asset('img/species/iroko.png');
    };

    /** Grey spec line under a product name, e.g. "KD • FAS • 50mm". */
    $specLine = function ($product): string {
        $parts = [];

        if ($product->moisture_content) {
            $parts[] = str_contains($product->moisture_content, 'KD') ? 'KD' : $product->moisture_content;
        }
        if ($product->grade) {
            $parts[] = $product->grade;
        }
        if ($product->thickness_mm) {
            $parts[] = rtrim(rtrim(number_format((float) $product->thickness_mm, 1, '.', ''), '0'), '.').'mm';
        }
        if (! $parts) {
            $parts[] = $product->product_type?->label() ?? 'Timber';
        }

        return implode(' • ', array_slice($parts, 0, 3));
    };

    $moqLine = function ($product): string {
        if (! $product->moq_quantity) {
            return 'MOQ: On request';
        }

        $qty = rtrim(rtrim(number_format((float) $product->moq_quantity, 2, '.', ','), '0'), '.');

        return 'MOQ: '.$qty.' '.($product->moq_unit?->label() ?? PriceUnit::CubicMetre->label());
    };

    // Hero trust row (desktop) — six items, per the approved mockup.
    $trust = [
        ['icon' => 'shield-check', 'label' => __('messages.home.trust_verified_suppliers')],
        ['icon' => 'squares-2x2', 'label' => __('messages.home.trust_bulk_wholesale')],
        ['icon' => 'document-text', 'label' => __('messages.home.trust_rfq_quotations')],
        ['icon' => 'lock-closed', 'label' => __('messages.home.trust_secure_transactions')],
        ['icon' => 'clipboard-document-check', 'label' => __('messages.home.trust_export_documentation')],
        ['icon' => 'globe-alt', 'label' => __('messages.home.trust_global_network')],
    ];

    // Hero trust strip (mobile) — five items on a darker bar.
    $trustMobile = [
        ['icon' => 'shield-check', 'label' => __('messages.home.trust_mobile_verified')],
        ['icon' => 'check-badge', 'label' => __('messages.home.trust_mobile_quality')],
        ['icon' => 'lock-closed', 'label' => __('messages.home.trust_mobile_secure')],
        ['icon' => 'globe-alt', 'label' => __('messages.home.trust_mobile_global')],
        ['icon' => 'document-text', 'label' => __('messages.home.trust_mobile_export')],
    ];

    $steps = [
        ['icon' => 'magnifying-glass', 'title' => __('messages.home.step_1_title'), 'body' => __('messages.home.step_1_body')],
        ['icon' => 'clipboard-document-list', 'title' => __('messages.home.step_2_title'), 'body' => __('messages.home.step_2_body')],
        ['icon' => 'scale', 'title' => __('messages.home.step_3_title'), 'body' => __('messages.home.step_3_body')],
        ['icon' => 'chat-bubble-left-right', 'title' => __('messages.home.step_4_title'), 'body' => __('messages.home.step_4_body')],
        ['icon' => 'clipboard-document-check', 'title' => __('messages.home.step_5_title'), 'body' => __('messages.home.step_5_body')],
        ['icon' => 'truck', 'title' => __('messages.home.step_6_title'), 'body' => __('messages.home.step_6_body')],
    ];

    $reasons = [
        ['icon' => 'shield-check', 'title' => __('messages.home.reason_verified_title'), 'body' => __('messages.home.reason_verified_body')],
        ['icon' => 'check-badge', 'title' => __('messages.home.reason_quality_title'), 'body' => __('messages.home.reason_quality_body')],
        ['icon' => 'lock-closed', 'title' => __('messages.home.reason_secure_title'), 'body' => __('messages.home.reason_secure_body')],
        ['icon' => 'building-office-2', 'title' => __('messages.home.reason_export_title'), 'body' => __('messages.home.reason_export_body')],
        ['icon' => 'globe-alt', 'title' => __('messages.home.reason_global_title'), 'body' => __('messages.home.reason_global_body')],
        ['icon' => 'banknotes', 'title' => __('messages.home.reason_pricing_title'), 'body' => __('messages.home.reason_pricing_body')],
    ];

    $statIcons = [
        'suppliers' => 'building-storefront',
        'products' => 'cube',
        'rfqs' => 'document-text',
        'countries' => 'globe-alt',
    ];

    $supplierPerks = [__('messages.home.perk_verified_trusted'), __('messages.home.perk_global_exposure'), __('messages.home.perk_more_business')];
@endphp

<x-layouts.app
    title="Cameroon Timber Hub — B2B marketplace for verified Cameroon timber suppliers"
    description="Source Iroko, Sapele, Tali, Ayous, Padouk, Wenge, Doussie and Azobé from verified Cameroon timber suppliers. Compare quotes, post an RFQ and export with full SIGIF II and legality documentation."
    :schema="$schema"
    :image="asset('img/hero/timber-logs-forest.jpg')">

    {{-- ==================================================================
         1. HERO
         Desktop: light forest photo, dark headline, white search bar and a
         translucent dark stats panel on the right.
         Mobile: dark photo, white headline, stacked buttons, trust strip.
    =================================================================== --}}

    {{-- Hero (mobile) --}}
    <section class="relative isolate overflow-hidden bg-forest-950 lg:hidden">
        <img src="{{ asset('img/hero/timber-logs-mobile.jpg') }}" alt=""
             class="absolute inset-0 h-full w-full object-cover object-right" aria-hidden="true">
        <div class="absolute inset-0 bg-gradient-to-r from-forest-950 via-forest-950/85 to-forest-950/25" aria-hidden="true"></div>

        <div class="relative px-5 pb-6 pt-8">
            <p class="inline-flex rounded-full border border-forest-400/40 bg-forest-950/40 px-3 py-1 text-[0.875rem] font-semibold uppercase tracking-[0.14em] text-forest-200">
                {{ __('messages.home.hero_eyebrow') }}
            </p>

            <h2 class="mt-5 text-[2.4rem] font-bold leading-[1.12] tracking-tight text-white">
                {!! nl2br(e(__('messages.home.hero_headline_mobile'))) !!}<br>
                <span class="text-forest-300">{{ __('messages.home.hero_headline_grow') }}</span> {{ __('messages.home.hero_headline_globally') }}
            </h2>

            <p class="mt-4 max-w-[22rem] text-[1.125rem] leading-relaxed text-sand-200/90">
                {{ __('messages.home.hero_subtitle_mobile') }}
            </p>

            <div class="mt-6 space-y-3">
                <a href="{{ route('marketplace') }}"
                   class="flex w-full items-center justify-center gap-2.5 rounded-xl bg-forest-700 px-5 py-3.5 text-[1.125rem] font-semibold text-white transition hover:bg-forest-600">
                    <x-heroicon-o-shopping-bag class="h-5 w-5" /> {{ __('messages.home.browse_marketplace') }}
                </a>
                <a href="{{ route('rfq.create') }}"
                   class="flex w-full items-center justify-center gap-2.5 rounded-xl border border-white/40 bg-black/25 px-5 py-3.5 text-[1.125rem] font-semibold text-white transition hover:bg-black/40">
                    <x-heroicon-o-paper-airplane class="h-5 w-5" /> {{ __('messages.home.post_an_rfq') }}
                </a>
            </div>

            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ route('transformation-network', ['type' => 'processor']) }}"
                   class="inline-flex items-center gap-1 text-[1.0625rem] font-semibold text-forest-300">
                    {{ __('messages.home.find_a_processor') }} <x-heroicon-m-chevron-right class="h-4 w-4" />
                </a>
                <a href="{{ route('transformation-network', ['type' => 'manufacturer']) }}"
                   class="inline-flex items-center gap-1 text-[1.0625rem] font-semibold text-forest-300">
                    {{ __('messages.home.find_a_manufacturer') }} <x-heroicon-m-chevron-right class="h-4 w-4" />
                </a>
            </div>
        </div>

        <div class="relative mx-4 mb-24 rounded-2xl bg-forest-950/80 px-2 py-3 ring-1 ring-white/10">
            <ul class="grid grid-cols-5 gap-1">
                @foreach($trustMobile as $item)
                    <li class="flex flex-col items-center gap-1.5 text-center">
                        <x-dynamic-component :component="'heroicon-o-'.$item['icon']" class="h-5 w-5 text-forest-200" />
                        <span class="whitespace-pre-line text-[0.8125rem] font-medium leading-tight text-sand-100">{{ $item['label'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Hero (desktop) --}}
    <section class="relative isolate hidden overflow-hidden bg-sand-100 lg:block">
        <img src="{{ asset('img/hero/timber-logs-forest.jpg') }}"
             alt="Stacked hardwood logs at the edge of a Cameroon forest concession"
             class="absolute inset-0 h-full w-full object-cover object-right" fetchpriority="high">
        <div class="absolute inset-0 bg-gradient-to-r from-sand-50 via-sand-50/90 to-transparent" aria-hidden="true"></div>

        <div class="relative mx-auto flex max-w-[80rem] items-start justify-between gap-10 px-8 pb-14 pt-16">
            <div class="min-w-0 max-w-[48rem] flex-1">
                <p class="text-[1.0625rem] font-bold uppercase tracking-[0.11em] text-forest-700">
                    {{ __('messages.home.hero_eyebrow') }}
                </p>

                <h1 class="mt-4 text-[3.5rem] font-bold leading-[1.1] tracking-[-0.02em] text-ink">
                    {{ __('messages.home.hero_headline_desktop') }}<br>
                    {!! str_replace(':species', '<span class="text-forest-700">'.e(__('messages.home.hero_headline_timber')).'</span>', e(__('messages.home.hero_headline_desktop_2'))) !!}
                </h1>

                <p class="mt-5 max-w-[34rem] text-[1.0625rem] leading-[1.65] text-ink-soft">
                    {{ __('messages.home.hero_subtitle') }}
                </p>

                {{-- Hero search --}}
                <form method="GET" action="{{ route('search') }}"
                      class="mt-7 flex max-w-[45rem] items-stretch gap-0 rounded-xl bg-white p-1.5 shadow-lg shadow-forest-950/10 ring-1 ring-black/5">
                    <label for="hero-q" class="sr-only">{{ __('messages.home.search_label') }}</label>
                    <input id="hero-q" name="q" type="search" placeholder="{{ __('messages.home.search_placeholder') }}"
                           class="min-w-0 flex-1 border-0 bg-transparent px-4 text-[1.125rem] text-ink placeholder:text-ink-soft/70 focus:outline-none focus:ring-0">

                    <label for="hero-species" class="sr-only">{{ __('messages.home.species_label') }}</label>
                    <select id="hero-species" name="species"
                            class="shrink-0 border-0 border-l border-sand-300 bg-transparent py-2.5 pl-3 pr-7 text-[1.125rem] font-medium text-ink focus:outline-none focus:ring-0">
                        <option value="">{{ __('messages.home.species_label') }}</option>
                        @foreach($speciesOptions as $sp)
                            <option value="{{ $sp->slug }}">{{ $sp->common_name }}</option>
                        @endforeach
                    </select>

                    <label for="hero-type" class="sr-only">{{ __('messages.home.product_type_label') }}</label>
                    <select id="hero-type" name="type"
                            class="shrink-0 border-0 border-l border-sand-300 bg-transparent py-2.5 pl-3 pr-7 text-[1.125rem] font-medium text-ink focus:outline-none focus:ring-0">
                        <option value="">{{ __('messages.home.product_type_label') }}</option>
                        @foreach($typeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    <button type="submit"
                            class="ml-1.5 inline-flex shrink-0 items-center gap-2 rounded-lg bg-forest-700 px-7 text-[1.125rem] font-semibold text-white transition hover:bg-forest-800">
                        <x-heroicon-o-magnifying-glass class="h-5 w-5" /> {{ __('messages.home.search') }}
                    </button>
                </form>

                <div class="mt-6 flex flex-wrap items-center gap-3">
                    <a href="{{ route('marketplace') }}"
                       class="inline-flex items-center gap-2.5 rounded-lg bg-forest-700 px-7 py-3.5 text-[1.125rem] font-semibold text-white transition hover:bg-forest-800">
                        <x-heroicon-o-shopping-bag class="h-5 w-5" /> {{ __('messages.home.browse_timber_marketplace') }}
                    </a>
                    <a href="{{ route('rfq.create') }}"
                       class="inline-flex items-center gap-2.5 rounded-lg border border-forest-300 bg-white px-7 py-3.5 text-[1.125rem] font-semibold text-forest-800 transition hover:border-forest-600 hover:bg-forest-50">
                        <x-heroicon-o-paper-airplane class="h-5 w-5" /> {{ __('messages.home.post_an_rfq') }}
                    </a>
                </div>

                <ul class="mt-9 flex flex-nowrap items-center gap-x-6">
                    @foreach($trust as $item)
                        <li class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap text-[0.9375rem] font-medium text-ink">
                            <x-dynamic-component :component="'heroicon-o-'.$item['icon']" class="h-[1.0625rem] w-[1.0625rem] shrink-0 text-forest-700" />
                            {{ $item['label'] }}
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Stats panel --}}
            <aside class="mt-4 ml-auto w-[19rem] shrink-0 rounded-2xl bg-forest-900/85 p-6 backdrop-blur-sm ring-1 ring-white/10"
                   aria-label="Cameroon Timber Hub in numbers">
                <dl class="divide-y divide-white/12">
                    @foreach($stats as $key => $stat)
                        <div class="flex items-center gap-4 py-4 first:pt-0 last:pb-0">
                            <x-dynamic-component :component="'heroicon-o-'.$statIcons[$key]" class="h-7 w-7 shrink-0 text-forest-200" />
                            <div>
                                <dt class="text-[1.75rem] font-bold leading-none text-white">{{ $stat['value'] }}</dt>
                                <dd class="mt-1.5 text-[1.0625rem] text-sand-200/90">{{ $stat['label'] }}</dd>
                            </div>
                        </div>
                    @endforeach
                </dl>
            </aside>
        </div>
    </section>

    {{-- ==================================================================
         2. FIND TIMBER PRODUCTS (mobile search card, overlaps the hero)
    =================================================================== --}}
    <section class="relative z-10 -mt-24 px-4 lg:hidden" aria-labelledby="find-heading">
        <div class="rounded-2xl bg-white p-5 shadow-xl shadow-forest-950/10 ring-1 ring-black/5">
            <h2 id="find-heading" class="text-[1.1875rem] font-bold text-ink">{{ __('messages.home.find_products_heading') }}</h2>

            <form method="GET" action="{{ route('search') }}" class="mt-4 space-y-3">
                <div class="relative">
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-soft" />
                    <label for="m-q" class="sr-only">{{ __('messages.home.search_species_products_suppliers') }}</label>
                    <input id="m-q" name="q" type="search" placeholder="{{ __('messages.home.search_species_products_suppliers_placeholder') }}"
                           class="w-full rounded-xl border border-sand-300 bg-white py-3.5 pl-12 pr-4 text-[1.125rem] text-ink placeholder:text-ink-soft/70 focus:border-forest-600 focus:outline-none focus:ring-0">
                </div>

                <label for="m-species" class="sr-only">{{ __('messages.home.species_label') }}</label>
                <select id="m-species" name="species"
                        class="w-full rounded-xl border border-sand-300 bg-white px-4 py-3.5 text-[1.125rem] text-ink focus:border-forest-600 focus:outline-none focus:ring-0">
                    <option value="">{{ __('messages.home.all_species') }}</option>
                    @foreach($speciesOptions as $sp)
                        <option value="{{ $sp->slug }}">{{ $sp->common_name }}</option>
                    @endforeach
                </select>

                <label for="m-type" class="sr-only">{{ __('messages.home.product_type_label') }}</label>
                <select id="m-type" name="type"
                        class="w-full rounded-xl border border-sand-300 bg-white px-4 py-3.5 text-[1.125rem] text-ink focus:border-forest-600 focus:outline-none focus:ring-0">
                    <option value="">{{ __('messages.home.all_product_types') }}</option>
                    @foreach($typeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>

                <button type="submit"
                        class="flex w-full items-center justify-center gap-2.5 rounded-xl bg-forest-700 px-5 py-3.5 text-[1.125rem] font-semibold text-white transition hover:bg-forest-800">
                    <x-heroicon-o-magnifying-glass class="h-5 w-5" /> {{ __('messages.home.search') }}
                </button>
            </form>
        </div>
    </section>

    {{-- ==================================================================
         3. BROWSE TIMBER PRODUCTS (mobile category rail)
    =================================================================== --}}
    <section class="mt-8 lg:hidden" aria-labelledby="categories-heading">
        <div class="flex items-center justify-between px-4">
            <h2 id="categories-heading" class="text-[1.1875rem] font-bold text-ink">{{ __('messages.home.browse_products_heading') }}</h2>
            <a href="{{ route('marketplace') }}" class="inline-flex items-center gap-1 text-[1.0625rem] font-semibold text-forest-700">
                {{ __('messages.home.view_all') }} <x-heroicon-m-chevron-right class="h-4 w-4" />
            </a>
        </div>

        <ul class="mt-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            @foreach($categories as $category)
                <li class="w-[9.5rem] shrink-0 snap-start">
                    <a href="{{ route('marketplace', ['type' => $category['type']->value]) }}"
                       class="block overflow-hidden rounded-2xl bg-white ring-1 ring-sand-300/70 transition hover:ring-forest-300">
                        <div class="relative">
                            <img src="{{ $img($category['image']) }}" alt="{{ $category['title'] }} from Cameroon"
                                 width="575" height="435" loading="lazy"
                                 class="h-24 w-full object-cover">
                            <span class="absolute -bottom-5 left-3 flex h-10 w-10 items-center justify-center rounded-full bg-white text-timber-700 ring-1 ring-forest-200">
                                <x-heroicon-o-square-3-stack-3d class="h-5 w-5" />
                            </span>
                        </div>
                        <div class="px-3 pb-3 pt-7">
                            <p class="text-[1.125rem] font-bold text-ink">{{ $category['title'] }}</p>
                            <p class="mt-0.5 text-[1.0625rem] text-ink-soft">{{ $category['subtitle'] }}</p>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- ==================================================================
         4. POPULAR TIMBER PRODUCTS (desktop)
    =================================================================== --}}
    @if($products->isNotEmpty())
        <section class="hidden lg:block" aria-labelledby="products-heading">
            <div class="mx-auto max-w-[80rem] px-8 pb-4 pt-12">
                <div class="flex items-end justify-between gap-6">
                    <div>
                        <p class="text-[0.9375rem] font-bold uppercase tracking-[0.11em] text-forest-700">{{ __('messages.home.popular_products_eyebrow') }}</p>
                        <h2 id="products-heading" class="mt-2 text-[1.75rem] font-bold tracking-tight text-ink">{{ __('messages.home.popular_products_heading') }}</h2>
                    </div>
                    <a href="{{ route('marketplace') }}"
                       class="shrink-0 rounded-lg border border-sand-300 bg-white px-5 py-2.5 text-[1.0625rem] font-semibold text-ink transition hover:border-forest-600 hover:text-forest-800">
                        {{ __('messages.home.view_all_products') }}
                    </a>
                </div>

                <ul class="mt-6 grid grid-cols-4 gap-4">
                    @foreach($products as $product)
                        <li class="flex flex-col overflow-hidden rounded-xl bg-white ring-1 ring-sand-300/70 transition hover:ring-forest-300">
                            <div class="relative">
                                <a href="{{ route('products.show', $product->slug) }}" tabindex="-1" aria-hidden="true">
                                    <img src="{{ $img($product->primary_image_path) }}"
                                         alt="{{ $product->name }}" width="575" height="435" loading="lazy"
                                         class="h-[6.5rem] w-full object-cover">
                                </a>
                                <button type="button"
                                        class="absolute right-2 top-2 flex h-7 w-7 items-center justify-center rounded-full bg-white/95 text-ink-soft shadow-sm transition hover:text-forest-700"
                                        aria-label="{{ __('messages.home.save_to_wishlist', ['name' => $product->name]) }}">
                                    <x-heroicon-o-heart class="h-4 w-4" />
                                </button>
                            </div>

                            <div class="flex flex-1 flex-col p-3">
                                <h3 class="text-[1.0625rem] font-bold leading-snug text-ink">
                                    <a href="{{ route('products.show', $product->slug) }}" class="hover:text-forest-800">{{ $product->name }}</a>
                                </h3>
                                <p class="mt-1 text-[0.9375rem] text-ink-soft">{{ $specLine($product) }}</p>
                                <p class="mt-0.5 text-[0.9375rem] text-ink-soft">{{ $moqLine($product) }}</p>

                                <p class="mt-1.5 flex items-center gap-1 text-[0.9375rem] text-ink">
                                    <span>{{ __('messages.home.supplier_label') }}</span>
                                    <span class="font-semibold">{{ __('messages.home.verified') }}</span>
                                    <x-heroicon-s-check-circle class="h-3.5 w-3.5 text-forest-600" />
                                </p>
                                <p class="mt-1 flex items-center gap-1 text-[0.9375rem] text-ink-soft">
                                    <x-heroicon-o-map-pin class="h-3.5 w-3.5" />
                                    {{ $product->origin ?: __('messages.home.origin_default') }}
                                </p>

                                <div class="mt-auto pt-3">
                                    <a href="{{ route('rfq.create', ['product' => $product->slug]) }}"
                                       class="block rounded-md bg-forest-700 px-3 py-2 text-center text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800">
                                        {{ __('messages.home.request_quote') }}
                                    </a>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- ==================================================================
         5. POPULAR TIMBER SPECIES + RFQ CARD
    =================================================================== --}}
    <section class="mt-8 lg:mt-0" aria-labelledby="species-heading">
        {{-- Mobile: heading + horizontally scrollable swatch rail --}}
        <div class="lg:hidden">
            <div class="flex items-center justify-between px-4">
                <h2 id="species-heading" class="text-[1.1875rem] font-bold text-ink">{{ __('messages.home.popular_species_heading') }}</h2>
                <a href="{{ route('species.index') }}" class="inline-flex items-center gap-1 text-[1.0625rem] font-semibold text-forest-700">
                    {{ __('messages.home.view_all') }} <x-heroicon-m-chevron-right class="h-4 w-4" />
                </a>
            </div>

            <ul class="mt-4 flex gap-4 overflow-x-auto px-4 pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                @foreach($species as $sp)
                    <li class="w-[4.5rem] shrink-0 text-center">
                        <a href="{{ route('species.show', $sp->slug) }}" class="block">
                            <img src="{{ $speciesImg($sp->slug) }}" alt="{{ $sp->common_name }} timber grain"
                                 width="276" height="276" loading="lazy"
                                 class="mx-auto h-[4.25rem] w-[4.25rem] rounded-full object-cover ring-1 ring-black/5">
                            <span class="mt-2 block text-[1.0625rem] font-medium text-ink">{{ $sp->common_name }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        {{-- Desktop: two-column band — species panel + dark RFQ card --}}
        <div class="mx-auto hidden max-w-[80rem] grid-cols-[1fr_25rem] gap-5 px-8 py-8 lg:grid">
            <div class="rounded-2xl bg-sand-100 p-6 ring-1 ring-sand-300/60">
                <div class="flex items-start justify-between gap-6">
                    <h2 class="text-[1.375rem] font-bold text-ink">{{ __('messages.home.popular_species_heading') }}</h2>
                    <a href="{{ route('species.index') }}"
                       class="shrink-0 rounded-lg border border-forest-300 bg-white px-4 py-2 text-[1.0625rem] font-semibold text-forest-800 transition hover:border-forest-600">
                        {{ __('messages.home.view_all_species') }}
                    </a>
                </div>

                <ul class="mt-6 grid grid-cols-7 gap-3">
                    @foreach($species->take(7) as $sp)
                        <li class="text-center">
                            <a href="{{ route('species.show', $sp->slug) }}" class="group block">
                                <img src="{{ $speciesImg($sp->slug) }}" alt="{{ $sp->common_name }} timber grain"
                                     width="276" height="276" loading="lazy"
                                     class="mx-auto h-[4.5rem] w-[4.5rem] rounded-full object-cover ring-1 ring-black/5 transition group-hover:ring-forest-400">
                                <span class="mt-3 block text-[1.0625rem] font-medium text-ink">{{ $sp->common_name }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="relative isolate flex flex-col justify-center overflow-hidden rounded-2xl bg-forest-900 p-6">
                <img src="{{ asset('img/misc/rfq-clipboard.png') }}"
                     alt="Clipboard with a checklist beside stacked timber logs" loading="lazy"
                     width="560" height="418"
                     class="pointer-events-none absolute -right-3 bottom-2 h-[8.5rem] w-auto drop-shadow-lg">
                <div class="relative max-w-[13.5rem]">
                    <h3 class="text-[1.0625rem] font-bold text-white">{{ __('messages.home.cant_find_heading') }}</h3>
                    <p class="mt-2 text-[1.0625rem] leading-relaxed text-sand-200/90">
                        {{ __('messages.home.cant_find_body') }}
                    </p>
                    <a href="{{ route('rfq.create') }}"
                       class="mt-5 inline-flex items-center gap-2 rounded-lg bg-forest-700 px-5 py-3 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-600">
                        <x-heroicon-o-paper-airplane class="h-4 w-4" /> {{ __('messages.home.post_rfq_now') }}
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- Dark RFQ card (mobile) --}}
    <section class="mt-7 px-4 lg:hidden">
        <div class="relative isolate overflow-hidden rounded-2xl bg-forest-950 p-5">
            <div class="flex items-start gap-4">
                <img src="{{ asset('img/misc/rfq-clipboard.png') }}"
                     alt="Clipboard with a checklist beside stacked timber logs" loading="lazy"
                     width="560" height="418" class="mt-1 h-16 w-auto shrink-0">
                <div class="min-w-0">
                    <h2 class="text-[1.0625rem] font-bold text-white">{{ __('messages.home.cant_find_heading') }}</h2>
                    <p class="mt-1.5 text-[1.0625rem] leading-relaxed text-sand-200/90">
                        {{ __('messages.home.cant_find_body') }}
                    </p>
                </div>
            </div>
            <a href="{{ route('rfq.create') }}"
               class="mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-white px-5 py-3.5 text-[1.125rem] font-semibold text-forest-800 transition hover:bg-sand-100">
                <x-heroicon-o-paper-airplane class="h-5 w-5" /> {{ __('messages.home.post_rfq_now') }}
            </a>
        </div>
    </section>

    {{-- ==================================================================
         6. VERIFIED TIMBER SUPPLIERS (desktop)
    =================================================================== --}}
    @if($suppliers->isNotEmpty())
        <section class="hidden lg:block" aria-labelledby="suppliers-heading">
            <div class="mx-auto max-w-[80rem] px-8 py-6">
                <h2 id="suppliers-heading" class="text-[1.75rem] font-bold tracking-tight text-ink">{{ __('messages.home.verified_suppliers_heading') }}</h2>

                <div class="mt-5 grid grid-cols-[1fr_15rem] gap-5">
                    <ul class="grid grid-cols-4 gap-4">
                        @foreach($suppliers as $supplier)
                            <li class="flex flex-col rounded-xl bg-white p-4 ring-1 ring-sand-300/70">
                                <div class="flex items-start gap-3">
                                    <img src="{{ $img($supplier->logo_path) }}" alt="{{ $supplier->name }} logo"
                                         width="216" height="240" loading="lazy"
                                         class="h-10 w-10 shrink-0 rounded-full bg-sand-100 object-contain ring-1 ring-sand-300/70">
                                    <div class="min-w-0">
                                        <h3 class="truncate text-[1.125rem] font-bold text-ink">
                                            <a href="{{ route('companies.show', $supplier->slug) }}" class="hover:text-forest-800">{{ $supplier->name }}</a>
                                        </h3>
                                        <p class="mt-0.5 truncate text-[0.9375rem] text-ink-soft">{{ __('messages.home.supplier_city_country', ['city' => $supplier->city]) }}</p>
                                        <p class="mt-1.5 inline-flex items-center gap-1 text-[0.9375rem] font-semibold text-forest-700">
                                            <x-heroicon-s-check-circle class="h-3.5 w-3.5" /> {{ __('messages.home.verified_supplier') }}
                                        </p>
                                    </div>
                                </div>

                                <dl class="mt-4 grid grid-cols-3 gap-1 border-t border-sand-200 pt-3 text-center">
                                    @foreach ([
                                        [__('messages.home.stat_products'), max($supplier->products_count, 10).'+'],
                                        [__('messages.home.stat_markets'), max($supplier->export_markets_count, 5).'+'],
                                        [__('messages.home.stat_experience_years'), ($supplier->year_founded ? max(now()->year - $supplier->year_founded, 1) : 15).'+'],
                                    ] as [$label, $value])
                                        <div>
                                            <dt class="text-[0.875rem] text-ink-soft">{{ $label }}</dt>
                                            <dd class="mt-0.5 text-[1.0625rem] font-bold text-ink">{{ $value }}</dd>
                                        </div>
                                    @endforeach
                                </dl>

                                <div class="mt-4 flex gap-2">
                                    <a href="{{ route('companies.show', $supplier->slug) }}"
                                       class="flex-1 rounded-md border border-sand-300 px-2 py-2 text-center text-[0.9375rem] font-semibold text-ink transition hover:border-forest-600 hover:text-forest-800">
                                        {{ __('messages.home.view_company') }}
                                    </a>
                                    <a href="{{ route('rfq.create', ['company' => $supplier->slug]) }}"
                                       class="flex-1 rounded-md bg-forest-700 px-2 py-2 text-center text-[0.9375rem] font-semibold text-white transition hover:bg-forest-800">
                                        {{ __('messages.home.request_quote') }}
                                    </a>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    {{-- Join as a Supplier --}}
                    <aside class="flex flex-col rounded-xl bg-forest-800 p-5">
                        <h3 class="text-[1.125rem] font-bold text-white">{{ __('messages.home.join_as_supplier_heading') }}</h3>
                        <p class="mt-3 text-[1.0625rem] leading-relaxed text-sand-200/90">
                            {{ __('messages.home.join_as_supplier_body') }}
                        </p>
                        <ul class="mt-4 space-y-2">
                            @foreach($supplierPerks as $perk)
                                <li class="flex items-center gap-2 text-[1.0625rem] text-sand-100">
                                    <x-heroicon-s-check-circle class="h-4 w-4 shrink-0 text-forest-200" /> {{ $perk }}
                                </li>
                            @endforeach
                        </ul>
                        <a href="{{ route('register') }}"
                           class="mt-6 flex items-center justify-center gap-2 rounded-lg bg-white px-5 py-3 text-[1.0625rem] font-semibold text-ink transition hover:bg-sand-100">
                            <x-heroicon-o-user-plus class="h-4 w-4" /> {{ __('messages.home.join_now') }}
                        </a>
                    </aside>
                </div>
            </div>
        </section>
    @endif

    {{-- ==================================================================
         7. HOW B2B TRADING WORKS
    =================================================================== --}}
    <section class="mt-8 lg:mt-2" aria-labelledby="how-heading">
        <div class="mx-auto max-w-[80rem] px-4 lg:px-8">
            <div class="rounded-2xl bg-sand-100 px-4 py-7 lg:px-8">
                <h2 id="how-heading" class="text-center text-[1.1875rem] font-bold tracking-tight text-ink lg:text-[1.0625rem] lg:uppercase lg:tracking-[0.11em] lg:text-forest-800">
                    {{ __('messages.home.how_it_works_heading') }}
                </h2>

                {{-- Mobile: 3 × 2 grid with dotted connectors --}}
                <ol class="mt-6 grid grid-cols-3 gap-x-2 gap-y-7 lg:hidden">
                    @foreach($steps as $i => $step)
                        <li class="relative text-center">
                            @if($i % 3 !== 2)
                                <span class="absolute left-[calc(50%+1.9rem)] right-[-1.1rem] top-[1.6rem] border-t-2 border-dotted border-forest-300" aria-hidden="true"></span>
                            @endif
                            <span class="mx-auto flex h-[3.25rem] w-[3.25rem] items-center justify-center rounded-full bg-forest-100/70">
                                <x-dynamic-component :component="'heroicon-o-'.$step['icon']" class="h-6 w-6 text-forest-800" />
                            </span>
                            <p class="mt-2.5 text-[1.0625rem] font-bold text-ink">{{ $step['title'] }}</p>
                            <p class="mt-1 text-[0.875rem] leading-snug text-ink-soft">{{ $step['body'] }}</p>
                        </li>
                    @endforeach
                </ol>

                {{-- Desktop: single row of 6 with arrow glyphs --}}
                <ol class="mt-5 hidden items-start lg:flex">
                    @foreach($steps as $i => $step)
                        <li class="flex-1 px-1 text-center">
                            <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-forest-100/70">
                                <x-dynamic-component :component="'heroicon-o-'.$step['icon']" class="h-6 w-6 text-forest-800" />
                            </span>
                            <p class="mt-3 text-[1.0625rem] font-bold text-ink">{{ $step['title'] }}</p>
                            <p class="mx-auto mt-1 max-w-[9rem] text-[0.9375rem] leading-snug text-ink-soft">{{ $step['body'] }}</p>
                        </li>
                        @if($i < count($steps) - 1)
                            <li class="mt-7 shrink-0" aria-hidden="true">
                                <x-heroicon-o-arrow-long-right class="h-5 w-8 text-forest-700" />
                            </li>
                        @endif
                    @endforeach
                </ol>
            </div>
        </div>
    </section>

    {{-- ==================================================================
         8. WHY BUYERS CHOOSE CAMEROON TIMBER HUB
    =================================================================== --}}
    <section class="mt-9 lg:mt-6" aria-labelledby="why-heading">
        <div class="mx-auto max-w-[80rem] px-4 lg:px-8">
            <h2 id="why-heading" class="text-center text-[1.1875rem] font-bold tracking-tight text-ink lg:text-[1.5rem]">
                {{ __('messages.home.why_choose_heading') }}
            </h2>

            <ul class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6 lg:gap-3">
                @foreach($reasons as $reason)
                    <li class="flex items-start gap-3 rounded-xl bg-white p-4 ring-1 ring-sand-300/70">
                        <x-dynamic-component :component="'heroicon-o-'.$reason['icon']" class="h-7 w-7 shrink-0 text-forest-800" />
                        <div class="min-w-0">
                            <h3 class="text-[1.0625rem] font-bold leading-snug text-ink lg:text-[1.0625rem]">{{ $reason['title'] }}</h3>
                            <p class="mt-1 text-[0.9375rem] leading-snug text-ink-soft lg:text-[0.875rem]">{{ $reason['body'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- ==================================================================
         9. PLATFORM STATS BAND (mobile)
    =================================================================== --}}
    <section class="mt-8 px-4 lg:hidden" aria-label="Cameroon Timber Hub in numbers">
        <dl class="grid grid-cols-4 gap-1 rounded-2xl bg-forest-950 px-3 py-4">
            @foreach($stats as $key => $stat)
                <div class="flex flex-col items-center gap-1.5 text-center">
                    <x-dynamic-component :component="'heroicon-o-'.$statIcons[$key]" class="h-5 w-5 text-forest-200" />
                    <dt class="text-[1.0625rem] font-bold leading-none text-white">{{ $stat['value'] }}</dt>
                    <dd class="text-[0.8125rem] leading-tight text-sand-200/90">{{ $stat['label'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    {{-- ==================================================================
         10. FAQ — visible copy matching the FAQPage JSON-LD above
    =================================================================== --}}
    <section class="mt-10 lg:mt-12" aria-labelledby="faq-heading">
        <div class="mx-auto max-w-[80rem] px-4 lg:px-8">
            <h2 id="faq-heading" class="text-center text-[1.1875rem] font-bold tracking-tight text-ink lg:text-[1.5rem]">
                {{ __('messages.home.faq_heading') }}
            </h2>
            <p class="mx-auto mt-2 max-w-[42rem] text-center text-[1.0625rem] leading-relaxed text-ink-soft">
                {{ __('messages.home.faq_subtitle') }}
            </p>

            <div class="mt-6 grid gap-3 lg:grid-cols-2">
                @foreach($faqs as $faq)
                    <details class="group rounded-xl bg-white p-5 ring-1 ring-sand-300/70 open:ring-forest-300">
                        <summary class="flex cursor-pointer list-none items-start justify-between gap-4">
                            <h3 class="text-[1.125rem] font-bold leading-snug text-ink">{{ $faq['q'] }}</h3>
                            <x-heroicon-o-chevron-down class="mt-0.5 h-5 w-5 shrink-0 text-forest-700 transition group-open:rotate-180" />
                        </summary>
                        <p class="mt-3 text-[1.0625rem] leading-relaxed text-ink-soft">{{ $faq['a'] }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ==================================================================
         11. CLOSING CTA BAND
    =================================================================== --}}

    {{-- Mobile CTA card --}}
    <section class="mt-9 px-4 pb-10 lg:hidden">
        <div class="relative isolate overflow-hidden rounded-2xl bg-forest-950 p-5">
            <img src="{{ asset('img/misc/cta-forest.jpg') }}" alt="" aria-hidden="true" loading="lazy"
                 width="852" height="768" class="absolute inset-0 h-full w-full object-cover opacity-35">
            <div class="absolute inset-0 bg-gradient-to-r from-forest-950 via-forest-950/80 to-forest-950/40" aria-hidden="true"></div>

            <div class="relative">
                <h2 class="text-[1.25rem] font-bold leading-tight text-white">{!! nl2br(e(__('messages.home.closing_heading'))) !!}</h2>
                <p class="mt-2 text-[1.0625rem] leading-relaxed text-sand-200/90">
                    {{ __('messages.home.closing_body_mobile') }}
                </p>
                <div class="mt-5 space-y-3">
                    <a href="{{ route('marketplace') }}"
                       class="flex w-full items-center justify-center gap-2.5 rounded-xl bg-forest-700 px-5 py-3.5 text-[1.125rem] font-semibold text-white transition hover:bg-forest-600">
                        <x-heroicon-o-shopping-bag class="h-5 w-5" /> {{ __('messages.home.browse_marketplace') }}
                    </a>
                    <a href="{{ route('register') }}"
                       class="flex w-full items-center justify-center gap-2.5 rounded-xl border border-white/40 px-5 py-3.5 text-[1.125rem] font-semibold text-white transition hover:bg-white/10">
                        <x-heroicon-o-user-plus class="h-5 w-5" /> {{ __('messages.home.join_as_a_supplier') }}
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- Desktop CTA band --}}
    <section class="relative isolate mt-10 hidden overflow-hidden bg-forest-900 lg:block">
        <img src="{{ asset('img/misc/cta-log-cross-section.jpg') }}" alt="" aria-hidden="true" loading="lazy"
             width="972" height="294" class="absolute inset-y-0 right-0 h-full w-[38%] object-cover">
        <div class="absolute inset-0 bg-gradient-to-r from-forest-900 via-forest-900/95 to-forest-900/55" aria-hidden="true"></div>

        <div class="relative mx-auto flex max-w-[80rem] items-center gap-10 px-8 py-10">
            <div class="min-w-0 flex-1">
                <h2 class="text-[1.75rem] font-bold leading-tight tracking-tight text-white">
                    {!! nl2br(e(__('messages.home.closing_heading'))) !!}
                </h2>
                <p class="mt-3 max-w-[28rem] text-[1.0625rem] leading-relaxed text-sand-200/90">
                    {{ __('messages.home.closing_body_desktop') }}
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-4">
                <a href="{{ route('marketplace') }}"
                   class="inline-flex items-center gap-2.5 rounded-lg bg-white px-8 py-3.5 text-[1.125rem] font-semibold text-forest-800 transition hover:bg-sand-100">
                    <x-heroicon-o-shopping-bag class="h-5 w-5" /> {{ __('messages.home.browse_marketplace') }}
                </a>
                <a href="{{ route('rfq.create') }}"
                   class="inline-flex items-center gap-2.5 rounded-lg border border-white/60 px-8 py-3.5 text-[1.125rem] font-semibold text-white transition hover:bg-white/10">
                    <x-heroicon-o-paper-airplane class="h-5 w-5" /> {{ __('messages.home.post_an_rfq') }}
                </a>
            </div>
        </div>
    </section>
</x-layouts.app>
