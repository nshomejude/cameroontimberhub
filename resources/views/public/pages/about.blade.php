@php
    /**
     * About — dedicated marketing layout for the approved mockup.
     *
     * Every string on this page still comes from the CMS `pages` row so admins
     * keep the editorial control they have today: the hero, pillars, mission,
     * vision, "Why Cameroon" list and CTA all read from `$page->data`, and the
     * long-form prose blocks are rendered verbatim in the "Our story" section.
     * The defaults below are only a safety net for a page row that predates
     * these keys — they are never used once PageSeeder has run.
     */
    $d = is_array($page->data) ? $page->data : [];

    $pillars = $d['pillars'] ?? [
        ['icon' => 'shield-check', 'title' => 'Trusted & Verified', 'text' => 'Supplier documents are reviewed before a company can be listed as verified.'],
        ['icon' => 'sparkles', 'title' => 'Sustainable Trade', 'text' => 'We promote legal, responsible and traceable forestry for future generations.'],
        ['icon' => 'globe-alt', 'title' => 'Global Reach', 'text' => 'Connecting Cameroonian timber suppliers with buyers worldwide.'],
        ['icon' => 'users', 'title' => 'Growth & Impact', 'text' => 'Empowering local businesses, creating jobs and driving economic growth.'],
    ];

    $whyPoints = $d['why_points'] ?? [];
    $blocks = $d['blocks'] ?? [];
@endphp

<x-layouts.app
    :title="$page->title"
    :description="$page->meta_description"
    :schema="$schema ?? null"
    :breadcrumbs="$breadcrumbs ?? null"
    :image="asset('img/hero/about-timber-world.jpg')">

    {{-- ==================================================================
         1. HERO
    =================================================================== --}}
    <section class="relative isolate overflow-hidden bg-forest-950" aria-labelledby="about-heading">
        <img src="{{ asset('img/hero/about-timber-world.jpg') }}"
             alt="Stacked Cameroonian hardwood logs in front of a world map of export routes"
             width="1792" height="692" fetchpriority="high"
             class="absolute inset-0 h-full w-full object-cover object-right">
        <div class="absolute inset-0 bg-gradient-to-r from-forest-950 via-forest-950/90 to-forest-950/20 lg:to-transparent" aria-hidden="true"></div>

        <div class="relative mx-auto max-w-[80rem] px-5 pb-10 pt-9 lg:px-8 lg:pb-16 lg:pt-14">
            <p class="text-[0.75rem] font-bold uppercase tracking-[0.12em] text-timber-300 lg:text-[0.8125rem]">
                {{ $d['eyebrow'] ?? 'About '.config('app.name') }}
            </p>

            <h1 id="about-heading" class="mt-4 max-w-[30rem] text-[2.1rem] font-bold leading-[1.14] tracking-tight text-white lg:max-w-[36rem] lg:text-[3.25rem] lg:leading-[1.1]">
                {{ $page->h1 ?: $page->title }}
            </h1>

            @if ($intro = ($d['intro'] ?? $page->meta_description))
                <p class="mt-5 max-w-[26rem] text-[0.95rem] leading-relaxed text-sand-200/90 lg:max-w-[34rem] lg:text-[1.0625rem] lg:leading-[1.7]">
                    {{ $intro }}
                </p>
            @endif

            <div class="mt-7 flex flex-wrap items-center gap-3">
                <a href="{{ route('register') }}"
                   class="inline-flex items-center gap-2.5 rounded-lg bg-forest-700 px-7 py-3.5 text-[0.9375rem] font-semibold text-white transition hover:bg-forest-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                    <x-heroicon-o-user-plus class="h-5 w-5" /> Join as Supplier
                </a>
                <a href="{{ route('marketplace') }}"
                   class="inline-flex items-center gap-2.5 rounded-lg border border-white/50 px-7 py-3.5 text-[0.9375rem] font-semibold text-white transition hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                    <x-heroicon-o-shopping-cart class="h-5 w-5" /> Explore Marketplace
                </a>
            </div>
        </div>
    </section>

    {{-- ==================================================================
         2. PILLARS
    =================================================================== --}}
    <section class="bg-sand-100" aria-label="What Cameroon Timber Hub stands for">
        <ul class="mx-auto grid max-w-[80rem] grid-cols-2 gap-x-4 gap-y-8 px-5 py-9 sm:grid-cols-4 lg:gap-x-0 lg:divide-x lg:divide-sand-300 lg:px-8 lg:py-10">
            @foreach ($pillars as $pillar)
                <li class="flex flex-col items-center gap-3 text-center lg:flex-row lg:items-start lg:gap-4 lg:px-6 lg:text-left lg:first:pl-0 lg:last:pr-0">
                    <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-forest-900 text-forest-200 lg:h-[3.25rem] lg:w-[3.25rem]" aria-hidden="true">
                        <x-dynamic-component :component="'heroicon-o-'.($pillar['icon'] ?? 'check-badge')" class="h-6 w-6" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-[0.9375rem] font-bold leading-snug text-ink">{{ $pillar['title'] ?? '' }}</h2>
                        <p class="mt-1.5 text-[0.8125rem] leading-relaxed text-ink-soft">{{ $pillar['text'] ?? '' }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- ==================================================================
         3. MISSION / VISION  +  WHY CAMEROON  +  LIVE PLATFORM COUNTERS
    =================================================================== --}}
    <section class="bg-white">
        <div class="mx-auto grid max-w-[80rem] gap-10 px-5 py-10 lg:grid-cols-[1fr_1.35fr] lg:gap-14 lg:px-8 lg:py-14">

            <div class="lg:border-r lg:border-sand-200 lg:pr-14">
                @foreach ([['Our Mission', $d['mission'] ?? null], ['Our Vision', $d['vision'] ?? null]] as $i => [$heading, $body])
                    @if ($body)
                        <div @class(['mt-9 border-t border-sand-200 pt-9' => $i > 0])>
                            <p class="eyebrow">{{ $heading }}</p>
                            <h2 class="mt-2 text-[1.6rem] font-bold tracking-tight text-ink lg:text-[1.75rem]">{{ $heading }}</h2>
                            <span class="mt-3 block h-[3px] w-10 rounded-full bg-forest-700" aria-hidden="true"></span>
                            <p class="mt-5 text-[0.9375rem] leading-[1.75] text-ink-soft">{{ $body }}</p>
                        </div>
                    @endif
                @endforeach
            </div>

            <div class="grid gap-8 lg:grid-cols-[1fr_0.9fr] lg:gap-10">
                <div>
                    @if ($whyTitle = ($d['why_title'] ?? null))
                        <h2 class="text-[1.3rem] font-bold tracking-tight text-forest-700 lg:text-[1.4rem]">{{ $whyTitle }}</h2>
                        <span class="mt-3 block h-[3px] w-10 rounded-full bg-timber-500" aria-hidden="true"></span>
                    @endif

                    @if ($whyIntro = ($d['why_intro'] ?? null))
                        <p class="mt-5 text-[0.9375rem] leading-[1.75] text-ink-soft">{{ $whyIntro }}</p>
                    @endif

                    @if ($whyPoints)
                        <ul class="mt-6 space-y-3">
                            @foreach ($whyPoints as $point)
                                <li class="flex items-start gap-2.5 text-[0.9375rem] leading-snug text-ink">
                                    <x-heroicon-s-check-circle class="mt-0.5 h-5 w-5 shrink-0 text-timber-600" aria-hidden="true" />
                                    <span>{{ is_array($point) ? ($point['text'] ?? '') : $point }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Live counters. Rendered only when the database actually
                     supports them — see PageController::platformStats(). --}}
                @if (! empty($stats))
                    <aside class="self-start rounded-2xl bg-sand-100 p-6 ring-1 ring-sand-200" aria-labelledby="about-numbers">
                        <h2 id="about-numbers" class="sr-only">Cameroon Timber Hub in numbers</h2>
                        <dl class="grid grid-cols-2 gap-x-5 gap-y-7">
                            @foreach ($stats as $stat)
                                <div class="flex items-start gap-3">
                                    <x-dynamic-component :component="'heroicon-o-'.$stat['icon']" class="mt-0.5 h-7 w-7 shrink-0 text-timber-600" aria-hidden="true" />
                                    <div class="min-w-0">
                                        <dt class="text-[1.4rem] font-bold leading-none text-forest-700">{{ $stat['value'] }}</dt>
                                        <dd class="mt-1.5 text-[0.8125rem] leading-tight text-ink-soft">{{ $stat['label'] }}</dd>
                                    </div>
                                </div>
                            @endforeach
                        </dl>
                        <p class="mt-6 text-[0.6875rem] leading-relaxed text-ink-soft/80">
                            Counted live from published records on this platform.
                        </p>
                    </aside>
                @endif
            </div>
        </div>
    </section>

    {{-- ==================================================================
         4. CMS PROSE — the editable long-form body kept from the old /about
    =================================================================== --}}
    @if ($blocks)
        <section class="bg-sand-50" aria-labelledby="story-heading">
            <div class="mx-auto max-w-[46rem] px-5 py-10 lg:px-8 lg:py-14">
                <p class="eyebrow">{{ $d['story_eyebrow'] ?? 'Our story' }}</p>
                <h2 id="story-heading" class="mt-2 text-[1.6rem] font-bold tracking-tight text-ink lg:text-[1.75rem]">
                    {{ $d['story_title'] ?? 'How Cameroon Timber Hub works' }}
                </h2>
                <span class="mt-3 block h-[3px] w-10 rounded-full bg-forest-700" aria-hidden="true"></span>

                @foreach ($blocks as $block)
                    @php $type = $block['type'] ?? 'paragraph'; @endphp

                    @if ($type === 'heading')
                        <h3 class="mt-9 text-[1.15rem] font-bold tracking-tight text-ink">{{ $block['content'] ?? '' }}</h3>
                    @elseif ($type === 'subheading')
                        <h4 class="mt-7 text-[1rem] font-semibold text-ink">{{ $block['content'] ?? '' }}</h4>
                    @elseif ($type === 'list')
                        <ul class="mt-4 space-y-2.5">
                            @foreach ($block['items'] ?? [] as $item)
                                <li class="flex items-start gap-2.5 text-[0.9375rem] leading-relaxed text-ink-soft">
                                    <x-heroicon-s-check-circle class="mt-1 h-[1.05rem] w-[1.05rem] shrink-0 text-forest-600" aria-hidden="true" />
                                    <span>{{ is_array($item) ? ($item['text'] ?? '') : $item }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-4 text-[0.9375rem] leading-[1.75] text-ink-soft">{{ $block['content'] ?? '' }}</p>
                    @endif
                @endforeach
            </div>
        </section>
    @endif

    {{-- ==================================================================
         5. CTA BAND
    =================================================================== --}}
    <section class="relative isolate overflow-hidden bg-forest-950" aria-labelledby="about-cta-heading">
        <img src="{{ asset('img/misc/cta-forest.jpg') }}" alt="" aria-hidden="true" loading="lazy"
             class="absolute inset-0 h-full w-full object-cover">
        <div class="absolute inset-0 bg-forest-950/85" aria-hidden="true"></div>

        <div class="relative mx-auto flex max-w-[80rem] flex-col gap-6 px-5 py-9 text-center lg:flex-row lg:items-center lg:px-8 lg:py-10 lg:text-left">
            <div class="min-w-0 flex-1">
                <h2 id="about-cta-heading" class="text-[1.35rem] font-bold leading-tight tracking-tight text-white lg:text-[1.75rem]">
                    {{ $d['cta_title'] ?? 'Be part of Africa’s timber success story' }}
                </h2>
                <p class="mx-auto mt-2.5 max-w-[34rem] text-[0.875rem] leading-relaxed text-sand-200/90 lg:mx-0">
                    {{ $d['cta_text'] ?? 'Join the verified buyers and suppliers building a transparent, sustainable timber trade ecosystem.' }}
                </p>
            </div>

            <div class="flex shrink-0 flex-col gap-3 sm:flex-row sm:justify-center lg:gap-4">
                <a href="{{ route('rfq.create') }}"
                   class="inline-flex items-center justify-center gap-2.5 rounded-lg border border-white/60 px-7 py-3.5 text-[0.9375rem] font-semibold text-white transition hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                    <x-heroicon-o-document-text class="h-5 w-5" /> I’m a Buyer
                </a>
                <a href="{{ route('register') }}"
                   class="inline-flex items-center justify-center gap-2.5 rounded-lg bg-timber-500 px-7 py-3.5 text-[0.9375rem] font-semibold text-white transition hover:bg-timber-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                    <x-heroicon-o-user-plus class="h-5 w-5" /> I’m a Supplier
                </a>
            </div>
        </div>
    </section>
</x-layouts.app>
