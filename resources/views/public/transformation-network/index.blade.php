<x-layouts.app
    title="Transformation Network — verified Cameroon processors & manufacturers"
    description="Find verified timber processors and manufacturers in Cameroon. Filter by capability, region and species.">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-6xl px-4 py-12">
            <p class="eyebrow">Domestic market</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl">Transformation Network</h1>
            <p class="mt-3 max-w-2xl text-lg text-ink-soft dark:text-[#b3ab9b]">Find verified processors and manufacturers in Cameroon's timber transformation sector.</p>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('transformation-network', ['type' => 'processor']) }}" @class([
                    'rounded-full px-5 py-2.5 text-sm font-semibold transition',
                    'bg-forest-700 text-white' => $type === 'processor',
                    'border border-sand-300 bg-white text-ink hover:border-forest-300' => $type !== 'processor',
                ])>Find a Processor</a>
                <a href="{{ route('transformation-network', ['type' => 'manufacturer']) }}" @class([
                    'rounded-full px-5 py-2.5 text-sm font-semibold transition',
                    'bg-forest-700 text-white' => $type === 'manufacturer',
                    'border border-sand-300 bg-white text-ink hover:border-forest-300' => $type !== 'manufacturer',
                ])>Find a Manufacturer</a>
                <a href="{{ route('transformation-network.match') }}"
                   class="rounded-full border border-forest-300 bg-white px-5 py-2.5 text-sm font-semibold text-forest-700 transition hover:bg-forest-50">
                    Find a Transformer for my stock
                </a>
            </div>
        </div>
    </section>

    <div class="mx-auto max-w-6xl px-4 py-10">
        <form method="get" class="flex flex-wrap items-end gap-3 rounded-2xl border border-sand-200 dark:border-[#2c2a24] bg-white dark:bg-[#1f1d18] p-5">
            @if ($type !== '')
                <input type="hidden" name="type" value="{{ $type }}">
            @endif
            <div class="min-w-[10rem] flex-1">
                <label class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">Region</label>
                <input type="text" name="region" value="{{ $region }}" placeholder="e.g. Littoral"
                       class="w-full rounded-lg border border-sand-300 dark:border-[#3a352e] bg-white dark:bg-[#1f1d18] px-3 py-2.5 text-[0.9375rem] text-ink dark:text-[#f1ece1] focus:border-forest-500 focus:ring-2 focus:ring-forest-100 focus:outline-none">
            </div>
            <div class="min-w-[10rem] flex-1">
                <label class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">Capability</label>
                <select name="capability" class="w-full rounded-lg border border-sand-300 dark:border-[#3a352e] bg-white dark:bg-[#1f1d18] px-3 py-2.5 text-[0.9375rem] text-ink dark:text-[#f1ece1] focus:border-forest-500 focus:ring-2 focus:ring-forest-100 focus:outline-none">
                    <option value="">Any capability</option>
                    @foreach ($businessTypes as $businessType)
                        <option value="{{ $businessType }}" @selected($capability === $businessType)>{{ $businessType }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">Filter</button>
        </form>

        @if ($companies->isNotEmpty())
            <div class="mt-8 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($companies as $company)
                    <x-supplier-card :company="$company" />
                @endforeach
            </div>

            <div class="mt-8">{{ $companies->links() }}</div>
        @else
            <div class="mt-8 rounded-2xl border border-dashed border-sand-300 dark:border-[#3a352e] p-10 text-center">
                <x-heroicon-o-building-office-2 class="mx-auto h-8 w-8 text-ink-soft" />
                <p class="mt-3 text-[1.0625rem] font-semibold text-ink dark:text-sand-100">No processors or manufacturers match these filters yet</p>
                <p class="mt-1 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">Try widening your search, or use "Find a Transformer" to match by stock.</p>
            </div>
        @endif
    </div>

</x-layouts.app>
