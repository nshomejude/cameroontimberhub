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

    <div class="mx-auto max-w-7xl px-4 py-10">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-4">

            {{-- Filter sidebar --}}
            <aside class="lg:col-span-1">
                <form method="GET" action="{{ route('logistics-directory') }}" class="space-y-6 rounded-2xl border border-sand-200 dark:border-[#2c2a24] bg-white dark:bg-[#1f1d18] p-5">
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
