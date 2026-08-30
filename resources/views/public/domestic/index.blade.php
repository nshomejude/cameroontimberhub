@php($field = 'w-full rounded-lg border border-sand-300 dark:border-[#3a352e] bg-white dark:bg-[#1f1d18] px-3 py-2.5 text-[0.9375rem] text-ink dark:text-[#f1ece1] focus:border-forest-500 focus:ring-2 focus:ring-forest-100 focus:outline-none')

<x-layouts.app
    title="Buy Cameroon Wood — domestic timber search"
    description="Find Cameroon timber for your workshop: filter by species, grade, dimensions, quantity, treatment and delivery region."
    :breadcrumbs="$breadcrumbs">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-7xl px-4 py-12">
            <p class="eyebrow">Domestic market</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl">Buy Cameroon Wood</h1>
            <p class="mt-3 max-w-2xl text-lg text-ink-soft dark:text-[#b3ab9b]">Timber and wood products from verified Cameroonian suppliers, ready for local workshops, joineries and kiln dryers.</p>
        </div>
    </section>

    <div class="mx-auto max-w-7xl px-4 py-10">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-4">

            {{-- Filter sidebar --}}
            <aside class="lg:col-span-1">
                <form method="GET" action="{{ route('domestic.marketplace') }}" class="space-y-6 rounded-2xl border border-sand-200 dark:border-[#2c2a24] bg-white dark:bg-[#1f1d18] p-5">
                    <div>
                        <label for="q" class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">Search</label>
                        <input type="text" name="q" id="q" value="{{ $filters['q'] }}" placeholder="e.g. Iroko planks" class="{{ $field }}">
                    </div>

                    <div>
                        <p class="mb-2 text-sm font-semibold text-ink dark:text-sand-100">Species</p>
                        <div class="max-h-48 space-y-1.5 overflow-y-auto pr-1">
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
                        <div class="space-y-1.5">
                            @foreach ($typeFacets as $facet)
                                <label class="flex items-center justify-between gap-2 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">
                                    <span class="flex items-center gap-2">
                                        <input type="checkbox" name="types[]" value="{{ $facet['value'] }}" @checked(in_array($facet['value'], $filters['types'], true))
                                               class="rounded border-sand-300 text-forest-700 focus:ring-forest-500">
                                        {{ $facet['label'] }}
                                    </span>
                                    <span class="text-[0.8125rem] tabular-nums text-ink-soft/70">{{ $facet['count'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label for="grade" class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">Grade</label>
                        <input type="text" name="grade" id="grade" value="{{ $filters['grade'] }}" placeholder="e.g. Select & Better" class="{{ $field }}">
                    </div>

                    <div>
                        <label for="treatment" class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">Treatment</label>
                        <select name="treatment" id="treatment" class="{{ $field }}">
                            <option value="">Any</option>
                            <option value="KD" @selected($filters['treatment'] === 'KD')>Kiln Dried</option>
                            <option value="Air" @selected($filters['treatment'] === 'Air')>Air Dried</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="max_thickness" class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">Max thickness (mm)</label>
                            <input type="number" name="max_thickness" id="max_thickness" value="{{ $filters['maxThicknessMm'] }}" class="{{ $field }}">
                        </div>
                        <div>
                            <label for="quantity" class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">Quantity</label>
                            <input type="number" name="quantity" id="quantity" value="{{ $filters['minQuantity'] }}" placeholder="e.g. 10" class="{{ $field }}">
                        </div>
                    </div>

                    <div id="location-filter" data-city-map="{{ json_encode($cityOptionsByRegion) }}" data-region-coords="{{ json_encode($regionCoordinates) }}">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-ink dark:text-sand-100">Region / Delivery</p>
                            <button type="button" id="use-my-location"
                                    class="inline-flex items-center gap-1 text-[0.8125rem] font-medium text-forest-700 hover:underline">
                                <x-heroicon-m-map-pin class="h-3.5 w-3.5" />
                                Use my location
                            </button>
                        </div>
                        <p id="location-status" class="mb-2 hidden text-[0.8125rem] text-ink-soft dark:text-[#b3ab9b]"></p>

                        <div class="space-y-1.5">
                            @foreach ($regionFacets as $facet)
                                <label class="flex items-center justify-between gap-2 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">
                                    <span class="flex items-center gap-2">
                                        <input type="radio" name="region" value="{{ $facet['value'] }}" @checked($filters['region'] === $facet['value'])
                                               class="region-radio border-sand-300 text-forest-700 focus:ring-forest-500">
                                        {{ $facet['label'] }}
                                    </span>
                                    <span class="text-[0.8125rem] tabular-nums text-ink-soft/70">{{ $facet['count'] }}</span>
                                </label>
                            @endforeach
                            <label class="flex items-center gap-2 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">
                                <input type="radio" name="region" value="" @checked($filters['region'] === '')
                                       class="region-radio border-sand-300 text-forest-700 focus:ring-forest-500">
                                Any region
                            </label>
                        </div>

                        <div class="mt-3">
                            <label for="city" class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">City / town</label>
                            <select name="city" id="city" class="{{ $field }}">
                                <option value="">Any city</option>
                                @foreach ($cityOptionsByRegion as $regionName => $cities)
                                    @foreach ($cities as $cityName)
                                        <option value="{{ $cityName }}" data-region="{{ $regionName }}" @selected($filters['city'] === $cityName)>
                                            {{ $cityName }} ({{ $regionName }})
                                        </option>
                                    @endforeach
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="w-full rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">
                        Apply filters
                    </button>
                </form>
            </aside>

            {{-- Results --}}
            <div class="lg:col-span-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]" aria-live="polite">
                        @if ($products->total() > 0)
                            Showing {{ $products->firstItem() }}–{{ $products->lastItem() }} of {{ $products->total() }} products
                        @else
                            No products match these filters
                        @endif
                    </p>
                    <form method="GET" action="{{ route('domestic.marketplace') }}" class="flex items-center gap-2">
                        @foreach ($filters as $key => $value)
                            @if ($key !== 'sort' && $value !== '' && $value !== null)
                                @foreach ((array) $value as $v)
                                    <input type="hidden" name="{{ is_array($value) ? "{$key}[]" : $key }}" value="{{ $v }}">
                                @endforeach
                            @endif
                        @endforeach
                        <label for="sort" class="sr-only">Sort products by</label>
                        <select name="sort" id="sort" onchange="this.form.submit()"
                                class="appearance-none rounded-lg border border-sand-300 bg-white py-2 pl-3 pr-8 text-[0.9375rem] text-ink transition focus:border-forest-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-200">
                            @foreach ($sortOptions as $value => $label)
                                <option value="{{ $value }}" @selected($filters['sort'] === $value)>Sort by: {{ $label }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>

                @if ($products->isNotEmpty())
                    <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($products as $product)
                            <x-product-card :product="$product" />
                        @endforeach
                    </div>

                    <div class="mt-8">
                        {{ $products->links() }}
                    </div>
                @else
                    <div class="mt-8 rounded-2xl border border-dashed border-sand-300 dark:border-[#3a352e] p-10 text-center">
                        <x-heroicon-o-magnifying-glass class="mx-auto h-8 w-8 text-ink-soft" />
                        <p class="mt-3 text-[1.0625rem] font-semibold text-ink dark:text-sand-100">No products match your filters yet</p>
                        <p class="mt-1 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">Try widening your search, or post an RFQ and let suppliers quote you.</p>
                        <a href="{{ route('rfq.create') }}" class="mt-4 inline-flex items-center gap-1.5 rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">
                            Post an RFQ
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <script>
            (function () {
                const wrap = document.getElementById('location-filter');
                if (!wrap) return;

                const cityMap = JSON.parse(wrap.dataset.cityMap || '{}');
                const regionCoords = JSON.parse(wrap.dataset.regionCoords || '{}');
                const citySelect = document.getElementById('city');
                const regionRadios = document.querySelectorAll('.region-radio');
                const locateBtn = document.getElementById('use-my-location');
                const statusEl = document.getElementById('location-status');

                function currentRegion() {
                    const checked = Array.from(regionRadios).find((r) => r.checked);
                    return checked ? checked.value : '';
                }

                function filterCitiesForRegion(region) {
                    Array.from(citySelect.options).forEach((option) => {
                        if (!option.value) return; // "Any city"
                        option.hidden = region !== '' && option.dataset.region !== region;
                    });
                    if (region !== '' && citySelect.selectedOptions[0]?.dataset.region !== region && citySelect.value !== '') {
                        citySelect.value = '';
                    }
                }

                regionRadios.forEach((radio) => {
                    radio.addEventListener('change', () => filterCitiesForRegion(currentRegion()));
                });
                filterCitiesForRegion(currentRegion());

                function nearestRegion(lat, lng) {
                    const toRad = (deg) => (deg * Math.PI) / 180;
                    let closest = null;
                    let closestDistance = Infinity;

                    Object.entries(regionCoords).forEach(([region, coord]) => {
                        const dLat = toRad(coord.lat - lat);
                        const dLng = toRad(coord.lng - lng);
                        const a = Math.sin(dLat / 2) ** 2
                            + Math.cos(toRad(lat)) * Math.cos(toRad(coord.lat)) * Math.sin(dLng / 2) ** 2;
                        const distance = 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

                        if (distance < closestDistance) {
                            closestDistance = distance;
                            closest = region;
                        }
                    });

                    return closest;
                }

                if (locateBtn) {
                    locateBtn.addEventListener('click', () => {
                        if (!navigator.geolocation) {
                            statusEl.textContent = 'Location is not supported by this browser.';
                            statusEl.classList.remove('hidden');
                            return;
                        }

                        statusEl.textContent = 'Finding your region…';
                        statusEl.classList.remove('hidden');

                        navigator.geolocation.getCurrentPosition(
                            (position) => {
                                const region = nearestRegion(position.coords.latitude, position.coords.longitude);
                                const radio = Array.from(regionRadios).find((r) => r.value === region);
                                if (radio) {
                                    radio.checked = true;
                                    filterCitiesForRegion(region);
                                    statusEl.textContent = `Detected region: ${region}. Applying…`;
                                    radio.closest('form').submit();
                                } else {
                                    statusEl.textContent = "Couldn't match your location to a region.";
                                }
                            },
                            () => {
                                statusEl.textContent = 'Location access was denied or unavailable.';
                            }
                        );
                    });
                }
            })();
    </script>
</x-layouts.app>
