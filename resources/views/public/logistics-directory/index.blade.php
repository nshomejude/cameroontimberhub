<x-layouts.app
    title="Logistics Directory — verified Cameroon transport & logistics companies"
    description="Find logistics and transport companies in Cameroon. Trusted and Tech-enabled verification tiers, filterable by region.">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-7xl px-4 py-12">
            <p class="eyebrow">Domestic market</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl">Logistics Directory</h1>
            <p class="mt-3 max-w-2xl text-lg text-ink-soft dark:text-[#b3ab9b]">Find transport and logistics companies in Cameroon's timber supply chain.</p>
        </div>
    </section>

    <div class="mx-auto max-w-7xl px-4 py-10" x-data="{ filtersOpen: false }">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-4">

            {{-- Mobile filter trigger — sidebars never appear inline on mobile, only as an on-demand drawer --}}
            <button type="button" @click="filtersOpen = true"
                    class="flex w-full items-center justify-center gap-2 rounded-full border border-sand-300 dark:border-[#3a352e] bg-white dark:bg-[#1f1d18] px-5 py-3 text-sm font-semibold text-ink dark:text-sand-100 lg:hidden">
                <x-heroicon-o-adjustments-horizontal class="h-4 w-4" />
                Filters
            </button>

            {{-- Backdrop (mobile only) --}}
            <div x-show="filtersOpen" x-cloak x-transition.opacity @click="filtersOpen = false"
                 class="fixed inset-0 z-40 bg-black/40 lg:hidden"></div>

            {{-- Filter drawer on mobile, static sidebar on desktop --}}
            <aside
                :class="filtersOpen ? 'translate-x-0' : '-translate-x-full'"
                class="fixed inset-y-0 left-0 z-50 w-[85%] max-w-sm overflow-y-auto bg-white pb-[env(safe-area-inset-bottom)] pt-[env(safe-area-inset-top)] shadow-xl transition-transform duration-300 ease-out dark:bg-[#1f1d18] lg:static lg:z-auto lg:col-span-1 lg:w-auto lg:max-w-none lg:translate-x-0 lg:overflow-visible lg:bg-transparent lg:p-0 lg:pt-0 lg:pb-0 lg:shadow-none lg:transition-none lg:dark:bg-transparent">
                <div class="flex items-center justify-between border-b border-sand-200 p-4 dark:border-[#2c2a24] lg:hidden">
                    <p class="text-base font-semibold text-ink dark:text-sand-100">Filters</p>
                    <button type="button" @click="filtersOpen = false" class="flex h-9 w-9 items-center justify-center rounded-lg text-ink-soft" aria-label="Close filters">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <form method="GET" action="{{ route('logistics-directory') }}" class="space-y-6 p-4 lg:rounded-2xl lg:border lg:border-sand-200 lg:dark:border-[#2c2a24] lg:bg-white lg:dark:bg-[#1f1d18] lg:p-5">
                    <div>
                        <label for="region" class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">Region</label>
                        <select name="region" id="region"
                                class="w-full rounded-lg border border-sand-300 dark:border-[#3a352e] bg-white dark:bg-[#1f1d18] px-3 py-2.5 text-[0.9375rem] text-ink dark:text-[#f1ece1] focus:border-forest-500 focus:ring-2 focus:ring-forest-100 focus:outline-none">
                            <option value="">Any region</option>
                            @foreach ($regionOptions as $option)
                                <option value="{{ $option }}" @selected($region === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <p class="mb-2 text-sm font-semibold text-ink dark:text-sand-100">Verification tier</p>
                        <div class="space-y-1.5">
                            <label class="flex items-center gap-2 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">
                                <input type="radio" name="tier" value="" @checked($tier === '')
                                       class="border-sand-300 text-forest-700 focus:ring-forest-500">
                                Any tier
                            </label>
                            @foreach ($tierOptions as $option)
                                <label class="flex items-center gap-2 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">
                                    <input type="radio" name="tier" value="{{ $option }}" @checked($tier === $option)
                                           class="border-sand-300 text-forest-700 focus:ring-forest-500">
                                    {{ $option }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <button type="submit" class="w-full rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">
                        Apply filters
                    </button>

                    @if ($region !== '' || $tier !== '')
                        <a href="{{ route('logistics-directory') }}" class="block text-center text-sm font-medium text-ink-soft hover:text-forest-700">Clear filters</a>
                    @endif
                </form>
            </aside>

            {{-- Sticky "show results" bar while the mobile drawer is open --}}
            <div x-show="filtersOpen" x-cloak class="fixed inset-x-0 bottom-0 z-50 border-t border-sand-200 bg-white p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-[0_-4px_12px_rgba(0,0,0,0.08)] dark:border-[#2c2a24] dark:bg-[#1f1d18] lg:hidden">
                <button type="button" @click="filtersOpen = false"
                        class="w-full rounded-full bg-forest-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-forest-800">
                    Show results
                </button>
            </div>

            {{-- Results --}}
            <div class="lg:col-span-3">
                @if ($companies->isNotEmpty())
                    <p class="text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]" aria-live="polite">
                        Showing {{ $companies->firstItem() }}–{{ $companies->lastItem() }} of {{ $companies->total() }} companies
                    </p>

                    <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($companies as $company)
                            @php($tierLabel = $tierOf($company))
                            <a href="{{ route('companies.show', $company->slug) }}"
                               class="block rounded-xl border border-sand-200 dark:border-[#2c2a24] bg-white dark:bg-[#1f1d18] p-5 transition hover:shadow-lg">
                                <div class="text-[1.0625rem] font-semibold text-ink dark:text-sand-100">{{ $company->name }}</div>
                                <div class="mt-1 text-sm text-ink-soft dark:text-[#b3ab9b]">{{ $company->type?->label() }} &middot; {{ $company->region }}</div>
                                <span @class([
                                    'mt-3 inline-block rounded-full px-2.5 py-1 text-xs font-semibold',
                                    'bg-forest-100 text-forest-800' => $tierLabel === \App\Http\Controllers\Public\LogisticsDirectoryController::TIER_TECH_ENABLED,
                                    'bg-timber-100 text-timber-800' => $tierLabel === \App\Http\Controllers\Public\LogisticsDirectoryController::TIER_TRUSTED,
                                    'bg-sand-100 text-ink-soft' => $tierLabel === \App\Http\Controllers\Public\LogisticsDirectoryController::TIER_UNVERIFIED,
                                ])>{{ $tierLabel }}</span>

                                @if ($company->relationLoaded('capacities') && $company->capacities->isNotEmpty())
                                    <div class="mt-3 flex flex-wrap gap-1.5">
                                        @foreach ($company->capacities->take(4) as $capacity)
                                            <span class="inline-block rounded-full bg-sand-100 px-2.5 py-1 text-xs font-medium text-ink-soft dark:bg-[#2c2a24] dark:text-[#b3ab9b]">
                                                {{ $capacity->capability }} &middot; {{ number_format((float) $capacity->quantity) }} {{ $capacity->unit }}/{{ $capacity->period }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </a>
                        @endforeach
                    </div>

                    <div class="mt-8">{{ $companies->links() }}</div>
                @else
                    <div class="rounded-2xl border border-dashed border-sand-300 dark:border-[#3a352e] p-10 text-center">
                        <x-heroicon-o-truck class="mx-auto h-8 w-8 text-ink-soft" />
                        <p class="mt-3 text-[1.0625rem] font-semibold text-ink dark:text-sand-100">No logistics companies match these filters yet</p>
                        <p class="mt-1 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">Try widening your search or check back soon.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

</x-layouts.app>
