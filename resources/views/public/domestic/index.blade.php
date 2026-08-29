<x-layouts.app
    title="Buy Cameroon Wood — domestic timber search"
    description="Find Cameroon timber for your workshop: filter by species, grade, dimensions, quantity, treatment and delivery region."
    :breadcrumbs="$breadcrumbs">

    <div class="mx-auto max-w-7xl px-4 py-8">
        <h1 class="text-2xl font-bold text-gray-900">Buy Cameroon Wood</h1>
        <p class="mt-1 text-gray-600">Timber and wood products from verified Cameroonian suppliers, ready for local workshops, joineries and kiln dryers.</p>

        <div class="mt-6 grid grid-cols-1 gap-8 lg:grid-cols-4">
            <form method="GET" action="{{ route('domestic.marketplace') }}" class="lg:col-span-1 space-y-6">
                <div>
                    <label for="q" class="block text-sm font-medium text-gray-700">Search</label>
                    <input type="text" name="q" id="q" value="{{ $filters['q'] }}" placeholder="e.g. Iroko planks"
                           class="mt-1 block w-full rounded-md border-gray-300">
                </div>

                <div>
                    <span class="block text-sm font-medium text-gray-700">Species</span>
                    @foreach ($speciesOptions as $slug => $label)
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" name="species[]" value="{{ $slug }}" @checked(in_array($slug, $filters['species'], true))>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>

                <div>
                    <span class="block text-sm font-medium text-gray-700">Product Type</span>
                    @foreach ($typeFacets as $facet)
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" name="types[]" value="{{ $facet['value'] }}" @checked(in_array($facet['value'], $filters['types'], true))>
                            {{ $facet['label'] }} ({{ $facet['count'] }})
                        </label>
                    @endforeach
                </div>

                <div>
                    <label for="grade" class="block text-sm font-medium text-gray-700">Grade</label>
                    <input type="text" name="grade" id="grade" value="{{ $filters['grade'] }}" placeholder="e.g. Select & Better"
                           class="mt-1 block w-full rounded-md border-gray-300">
                </div>

                <div>
                    <label for="treatment" class="block text-sm font-medium text-gray-700">Treatment</label>
                    <select name="treatment" id="treatment" class="mt-1 block w-full rounded-md border-gray-300">
                        <option value="">Any</option>
                        <option value="KD" @selected($filters['treatment'] === 'KD')>Kiln Dried</option>
                        <option value="Air" @selected($filters['treatment'] === 'Air')>Air Dried</option>
                    </select>
                </div>

                <div>
                    <label for="max_thickness" class="block text-sm font-medium text-gray-700">Max Thickness (mm)</label>
                    <input type="number" name="max_thickness" id="max_thickness" value="{{ $filters['maxThicknessMm'] }}"
                           class="mt-1 block w-full rounded-md border-gray-300">
                </div>

                <div>
                    <label for="quantity" class="block text-sm font-medium text-gray-700">Quantity you need</label>
                    <input type="number" name="quantity" id="quantity" value="{{ $filters['minQuantity'] }}" placeholder="e.g. 10"
                           class="mt-1 block w-full rounded-md border-gray-300">
                </div>

                <div>
                    <span class="block text-sm font-medium text-gray-700">Region / Delivery</span>
                    @foreach ($regionFacets as $facet)
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="radio" name="region" value="{{ $facet['value'] }}" @checked($filters['region'] === $facet['value'])>
                            {{ $facet['label'] }} ({{ $facet['count'] }})
                        </label>
                    @endforeach
                </div>

                <button type="submit" class="w-full rounded-md bg-emerald-700 px-4 py-2 text-white">Apply Filters</button>
            </form>

            <div class="lg:col-span-3">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-600">{{ $products->total() }} products</p>
                    <form method="GET" action="{{ route('domestic.marketplace') }}">
                        @foreach ($filters as $key => $value)
                            @if ($key !== 'sort' && $value !== '' && $value !== null)
                                @foreach ((array) $value as $v)
                                    <input type="hidden" name="{{ is_array($value) ? "{$key}[]" : $key }}" value="{{ $v }}">
                                @endforeach
                            @endif
                        @endforeach
                        <label for="sort" class="text-sm text-gray-600">Sort</label>
                        <select name="sort" id="sort" onchange="this.form.submit()" class="rounded-md border-gray-300">
                            @foreach ($sortOptions as $value => $label)
                                <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3">
                    @forelse ($products as $product)
                        <a href="{{ route('products.show', $product->slug) }}" class="block rounded-lg border border-gray-200 p-4 hover:shadow-md">
                            <div class="font-semibold text-gray-900">{{ $product->name }}</div>
                            <div class="text-sm text-gray-500">{{ $product->species?->common_name }}</div>
                            @if ($product->grade)
                                <div class="text-sm text-gray-500">Grade: {{ $product->grade }}</div>
                            @endif
                            @if ($product->moqLabel())
                                <div class="text-sm text-gray-500">Min order: {{ $product->moqLabel() }}</div>
                            @endif
                            @if ($product->company)
                                <div class="mt-2 text-xs text-gray-400">{{ $product->company->city }}, {{ $product->company->region }}</div>
                                @if ($product->company->deliveryTimeLabel())
                                    <div class="text-xs text-gray-400">Delivery: {{ $product->company->deliveryTimeLabel() }}</div>
                                @endif
                            @endif
                        </a>
                    @empty
                        <p class="col-span-full text-gray-500">No products match your filters yet. Try widening your search.</p>
                    @endforelse
                </div>

                <div class="mt-6">
                    {{ $products->links() }}
                </div>
            </div>
        </div>
    </div>

</x-layouts.app>
