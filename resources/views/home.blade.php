<x-layouts.app>
    {{-- Hero --}}
    <section class="relative overflow-hidden border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 via-sand-50 dark:via-[#14130f] to-sand-50 dark:to-[#14130f]">
        <x-brand-mark class="pointer-events-none absolute -right-24 -top-24 h-[28rem] w-[28rem] opacity-[0.05]" />
        <x-brand-mark class="pointer-events-none absolute -bottom-32 -left-28 h-96 w-96 opacity-[0.04]" />
        <div class="pointer-events-none absolute left-1/2 top-0 h-72 w-[40rem] -translate-x-1/2 rounded-full bg-forest-100/50 blur-3xl"></div>

        <div class="relative mx-auto max-w-5xl px-4 py-24 text-center sm:py-28">
            <p class="eyebrow">{{ __('messages.home.hero_eyebrow') }}</p>
            <h1 class="mx-auto mt-5 max-w-4xl font-display text-5xl font-semibold leading-[1.03] text-forest-950 dark:text-sand-100 sm:text-6xl lg:text-7xl">
                {{ __('messages.home.hero_title') }}
            </h1>
            <p class="mx-auto mt-6 max-w-2xl text-xl leading-relaxed text-ink-soft dark:text-[#b3ab9b]">
                {{ __('messages.home.hero_subtitle') }}
            </p>

            {{-- Search --}}
            <form method="GET" action="{{ route('directory') }}" class="mx-auto mt-9 flex max-w-xl items-center gap-2 rounded-2xl border border-sand-200 dark:border-[#2c2a24] bg-white dark:bg-[#1f1d18] p-2 shadow-xl shadow-forest-900/5">
                <span class="pl-3 text-ink-soft dark:text-[#b3ab9b]"><x-heroicon-m-magnifying-glass class="h-5 w-5" /></span>
                <input name="q" type="search" placeholder="Search verified exporters…"
                       class="min-w-0 flex-1 border-0 bg-transparent py-2.5 text-base text-ink dark:text-[#f1ece1] placeholder:text-ink-soft/60 focus:outline-none focus:ring-0">
                <button type="submit" class="shrink-0 rounded-xl bg-forest-700 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">Search</button>
            </form>

            @if($species->isNotEmpty())
                <div class="mt-5 flex flex-wrap items-center justify-center gap-2 text-sm">
                    <span class="text-ink-soft dark:text-[#b3ab9b]">Popular species:</span>
                    @foreach($species->take(6) as $sp)
                        <a href="{{ route('species.show', $sp->slug) }}" class="rounded-full border border-sand-200 dark:border-[#2c2a24] bg-white/70 dark:bg-[#1f1d18] px-3 py-1 font-medium text-forest-800 dark:text-forest-200 transition hover:border-forest-300 hover:bg-white dark:hover:bg-[#26241e]">{{ $sp->common_name }}</a>
                    @endforeach
                </div>
            @endif

            <dl class="mx-auto mt-16 grid max-w-2xl grid-cols-3 gap-6 border-t border-sand-200 dark:border-[#2c2a24] pt-8">
                @foreach ([
                    ['n' => $stats['companies'], 'label' => 'Verified exporters'],
                    ['n' => $stats['species'], 'label' => 'Timber species'],
                    ['n' => $stats['markets'], 'label' => 'Export markets'],
                ] as $stat)
                    <div>
                        <dt class="font-display text-4xl font-semibold text-forest-800 dark:text-forest-200 sm:text-5xl">{{ $stat['n'] }}</dt>
                        <dd class="mt-1 text-sm text-ink-soft dark:text-[#b3ab9b]">{{ $stat['label'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    {{-- Featured exporters --}}
    @if($featured->isNotEmpty())
        <section class="mx-auto max-w-6xl px-4 py-20">
            <div class="flex items-end justify-between gap-4">
                <div>
                    <p class="eyebrow">Verified directory</p>
                    <h2 class="mt-3 font-display text-3xl font-semibold text-forest-950 dark:text-sand-100">Featured verified exporters</h2>
                </div>
                <a href="{{ route('directory') }}" class="hidden shrink-0 items-center gap-1 text-sm font-semibold text-forest-700 dark:text-forest-300 transition hover:text-forest-900 sm:inline-flex">
                    View all <x-heroicon-m-arrow-right class="h-4 w-4" />
                </a>
            </div>
            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($featured as $company)
                    @include('public.partials.company-card', ['company' => $company])
                @endforeach
            </div>
        </section>
    @endif

    {{-- Trust pillars --}}
    <section class="border-y border-sand-200 dark:border-[#2c2a24] bg-sand-100/50 dark:bg-[#26241e]/50">
        <div class="mx-auto max-w-6xl px-4 py-20">
            <div class="text-center">
                <p class="eyebrow">{{ __('messages.home.pillars_title') }}</p>
                <h2 class="mt-3 font-display text-3xl font-semibold text-forest-950 dark:text-sand-100">Trade timber with confidence</h2>
            </div>
            <div class="mt-12 grid gap-6 md:grid-cols-3">
                @foreach ([
                    ['icon' => 'rectangle-stack', 'title' => 'home.pillar_directory_title', 'body' => 'home.pillar_directory_body'],
                    ['icon' => 'shield-check', 'title' => 'home.pillar_compliance_title', 'body' => 'home.pillar_compliance_body'],
                    ['icon' => 'paper-airplane', 'title' => 'home.pillar_rfq_title', 'body' => 'home.pillar_rfq_body'],
                ] as $pillar)
                    <div class="rounded-2xl border border-sand-200 dark:border-[#2c2a24] bg-white dark:bg-[#1f1d18] p-7 shadow-[0_1px_0_rgba(0,0,0,0.02)] transition hover:border-forest-200 hover:shadow-md">
                        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-forest-50 dark:bg-[#1b2c22] text-forest-700 dark:text-forest-300">
                            <x-dynamic-component :component="'heroicon-o-' . $pillar['icon']" class="h-6 w-6" />
                        </span>
                        <h3 class="mt-5 font-display text-xl font-semibold text-forest-900 dark:text-sand-100">{{ __('messages.' . $pillar['title']) }}</h3>
                        <p class="mt-2 leading-relaxed text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.' . $pillar['body']) }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Closing CTA band --}}
    <section class="mx-auto max-w-6xl px-4 py-20">
        <div class="relative overflow-hidden rounded-3xl bg-forest-800 px-8 py-16 text-center text-sand-100 sm:px-16">
            <x-brand-mark class="pointer-events-none absolute -right-12 -bottom-16 h-72 w-72 opacity-10" />
            <div class="relative">
                <h2 class="mx-auto max-w-2xl font-display text-3xl font-semibold text-white sm:text-4xl">Looking for verified Cameroonian timber?</h2>
                <p class="mx-auto mt-3 max-w-xl text-forest-200">Browse exporters by species and region, or send a request and we'll route it to matching verified suppliers.</p>
                <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                    <a href="{{ route('directory') }}" class="inline-flex items-center gap-2 rounded-full bg-timber-400 px-7 py-3.5 text-sm font-semibold text-forest-950 transition hover:bg-timber-300">
                        Browse exporters <x-heroicon-m-arrow-right class="h-4 w-4" />
                    </a>
                    <a href="{{ route('species.index') }}" class="inline-flex items-center gap-2 rounded-full border border-forest-600 px-7 py-3.5 text-sm font-semibold text-sand-100 transition hover:bg-forest-700">
                        Explore species
                    </a>
                </div>
            </div>
        </div>
    </section>
</x-layouts.app>
