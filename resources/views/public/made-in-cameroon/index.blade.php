<x-layouts.app
    title="Made in Cameroon — verified domestic manufacturers"
    description="Timber, furniture and wood products made in Cameroon by verified domestic manufacturers, processors and artisans."
    :breadcrumbs="$breadcrumbs">

    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Made in Cameroon</h1>
            <p class="mt-2 max-w-2xl text-gray-600">
                Listings on this page come from verified Cameroon-based manufacturers, processors
                and artisans — real in-country transformation, not raw material resale.
            </p>
        </div>

        @if ($products->isEmpty())
            <div class="rounded-lg border border-dashed border-gray-300 p-10 text-center text-gray-500">
                No qualifying listings yet.
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
