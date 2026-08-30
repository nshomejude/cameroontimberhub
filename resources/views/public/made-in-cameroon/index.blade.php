@php($field = 'w-full rounded-lg border border-sand-300 dark:border-[#3a352e] bg-white dark:bg-[#1f1d18] px-3 py-2.5 text-[0.9375rem] text-ink dark:text-[#f1ece1] focus:border-forest-500 focus:ring-2 focus:ring-forest-100 focus:outline-none')

<x-layouts.app
    title="Made in Cameroon — verified domestic manufacturers"
    description="Timber, furniture and wood products made in Cameroon by verified domestic manufacturers, processors and artisans."
    :breadcrumbs="$breadcrumbs">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-7xl px-4 py-12">
            <p class="eyebrow">Domestic market</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl">Made in Cameroon</h1>
            <p class="mt-3 max-w-2xl text-lg text-ink-soft dark:text-[#b3ab9b]">
                Listings on this page come from verified Cameroon-based manufacturers, processors
                and artisans — real in-country transformation, not raw material resale.
            </p>
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

                <form method="GET" action="{{ route('made-in-cameroon') }}" class="space-y-6 p-4 lg:rounded-2xl lg:border lg:border-sand-200 lg:dark:border-[#2c2a24] lg:bg-white lg:dark:bg-[#1f1d18] lg:p-5">
                    <div>
                        <p class="mb-2 text-sm font-semibold text-ink dark:text-sand-100">Species</p>
                        <div class="max-h-56 space-y-1.5 overflow-y-auto pr-1">
                            @foreach ($speciesOptions as $slug => $label)
                                <label class="flex items-center gap-2 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">
                                    <input type="checkbox" name="species[]" value="{{ $slug }}" @checked(in_array($slug, $filters['species'], true))
                                           class="rounded border-sand-300 text-forest-700 focus:ring-forest-500">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <p class="mb-2 text-sm font-semibold text-ink dark:text-sand-100">Product type</p>
                        <div class="max-h-56 space-y-1.5 overflow-y-auto pr-1">
                            @foreach ($typeOptions as $value => $label)
                                <label class="flex items-center gap-2 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">
                                    <input type="checkbox" name="types[]" value="{{ $value }}" @checked(in_array($value, $filters['types'], true))
                                           class="rounded border-sand-300 text-forest-700 focus:ring-forest-500">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <button type="submit" class="w-full rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">
                        Apply filters
                    </button>

                    @if ($filters['species'] !== [] || $filters['types'] !== [])
                        <a href="{{ route('made-in-cameroon') }}" class="block text-center text-sm font-medium text-ink-soft hover:text-forest-700">Clear filters</a>
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
                @if ($products->isNotEmpty())
                    <p class="text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]" aria-live="polite">
                        Showing {{ $products->firstItem() }}–{{ $products->lastItem() }} of {{ $products->total() }} listings
                    </p>

                    <div class="mt-5 grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($products as $product)
                            <x-product-card :product="$product" />
                        @endforeach
                    </div>

                    <div class="mt-8">
                        {{ $products->links() }}
                    </div>
                @else
                    <div class="rounded-2xl border border-dashed border-sand-300 dark:border-[#3a352e] p-10 text-center">
                        <x-heroicon-o-sparkles class="mx-auto h-8 w-8 text-ink-soft" />
                        <p class="mt-3 text-[1.0625rem] font-semibold text-ink dark:text-sand-100">No qualifying listings yet</p>
                        <p class="mt-1 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">Try widening your filters, or check back soon as verified domestic manufacturers are added.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

</x-layouts.app>
