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
        <form method="get" class="flex flex-wrap items-end gap-3 rounded-2xl border border-sand-200 dark:border-[#2c2a24] bg-white dark:bg-[#1f1d18] p-5">
            <div class="min-w-[10rem] flex-1">
                <label for="region" class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">Region</label>
                <input type="text" name="region" id="region" value="{{ $region }}" placeholder="e.g. Littoral"
                       class="w-full rounded-lg border border-sand-300 dark:border-[#3a352e] bg-white dark:bg-[#1f1d18] px-3 py-2.5 text-[0.9375rem] text-ink dark:text-[#f1ece1] focus:border-forest-500 focus:ring-2 focus:ring-forest-100 focus:outline-none">
            </div>
            <button type="submit" class="rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">Filter</button>
        </form>

        @if ($companies->isNotEmpty())
            <div class="mt-8 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($companies as $company)
                    @php($tier = $tierOf($company))
                    <a href="{{ route('companies.show', $company->slug) }}"
                       class="block rounded-xl border border-sand-200 dark:border-[#2c2a24] bg-white dark:bg-[#1f1d18] p-5 transition hover:shadow-lg">
                        <div class="text-[1.0625rem] font-semibold text-ink dark:text-sand-100">{{ $company->name }}</div>
                        <div class="mt-1 text-sm text-ink-soft dark:text-[#b3ab9b]">{{ $company->type?->label() }} &middot; {{ $company->region }}</div>
                        <span @class([
                            'mt-3 inline-block rounded-full px-2.5 py-1 text-xs font-semibold',
                            'bg-forest-100 text-forest-800' => $tier === \App\Http\Controllers\Public\LogisticsDirectoryController::TIER_TECH_ENABLED,
                            'bg-timber-100 text-timber-800' => $tier === \App\Http\Controllers\Public\LogisticsDirectoryController::TIER_TRUSTED,
                            'bg-sand-100 text-ink-soft' => $tier === \App\Http\Controllers\Public\LogisticsDirectoryController::TIER_UNVERIFIED,
                        ])>{{ $tier }}</span>
                    </a>
                @endforeach
            </div>

            <div class="mt-8">{{ $companies->links() }}</div>
        @else
            <div class="mt-8 rounded-2xl border border-dashed border-sand-300 dark:border-[#3a352e] p-10 text-center">
                <x-heroicon-o-truck class="mx-auto h-8 w-8 text-ink-soft" />
                <p class="mt-3 text-[1.0625rem] font-semibold text-ink dark:text-sand-100">No logistics companies match these filters yet</p>
                <p class="mt-1 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">Try widening your search or check back soon.</p>
            </div>
        @endif
    </div>

</x-layouts.app>
