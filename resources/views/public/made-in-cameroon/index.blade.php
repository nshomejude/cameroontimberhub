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

    <div class="mx-auto max-w-7xl px-4 py-10">
        @if ($products->isEmpty())
            <div class="rounded-2xl border border-dashed border-sand-300 dark:border-[#3a352e] p-10 text-center">
                <x-heroicon-o-sparkles class="mx-auto h-8 w-8 text-ink-soft" />
                <p class="mt-3 text-[1.0625rem] font-semibold text-ink dark:text-sand-100">No qualifying listings yet</p>
                <p class="mt-1 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">Check back soon — this page shows verified domestic manufacturers as they're added.</p>
            </div>
        @else
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($products as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>

            <div class="mt-8">
                {{ $products->links() }}
            </div>
        @endif
    </div>

</x-layouts.app>
