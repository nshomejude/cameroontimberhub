@php
    use App\Services\SearchService;

    $typeLabels = ['products' => 'Products', 'suppliers' => 'Suppliers', 'species' => 'Species'];

    /** Preserve every filter except the one being changed. */
    $urlWith = fn (array $overrides) => route('search', array_merge(
        array_filter($filters, fn ($v) => $v !== '' && $v !== null),
        $overrides,
    ));

    $total = $results->total();

    $breadcrumbs = [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => route('home')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Search', 'item' => route('search')],
    ];
@endphp

<x-layouts.app
    :title="$q !== '' ? 'Search results for “'.$q.'”' : 'Search timber products, suppliers and species'"
    description="Search verified Cameroon timber products, suppliers and species on Cameroon Timber Hub."
    :schema="['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $breadcrumbs]"
    {{-- Search results are thin, near-duplicate content: keep them out of the index. --}}
    noindex>

    {{-- ================= HERO ================= --}}
    <section class="relative isolate overflow-hidden bg-forest-950">
        <img src="{{ asset('img/hero/timber-logs-forest.jpg') }}" alt=""
             class="absolute inset-0 h-full w-full object-cover object-right" aria-hidden="true">
        <div class="absolute inset-0 bg-gradient-to-r from-forest-950 via-forest-950/90 to-forest-950/40" aria-hidden="true"></div>

        <div class="relative mx-auto max-w-[80rem] px-5 pb-7 pt-8 lg:px-8 lg:pb-10 lg:pt-12">
            <h1 class="text-[2rem] font-bold leading-tight tracking-tight text-white lg:text-[2.75rem]">Search Results</h1>

            <p class="mt-1.5 text-[0.9375rem] text-sand-200/90" aria-live="polite">
                @if ($q !== '')
                    {{ number_format($total) }} {{ Str::plural('result', $total) }} found for
                    <span class="font-semibold text-forest-300">“{{ $q }}”</span>
                @else
                    Search verified timber products, suppliers and species.
                @endif
            </p>

            <form method="GET" action="{{ route('search') }}" role="search"
                  class="mt-5 flex max-w-[46rem] items-center gap-2 rounded-xl bg-white p-2 shadow-lg shadow-forest-950/20">
                <label for="search-q" class="sr-only">Search timber products, suppliers and species</label>
                <x-heroicon-o-magnifying-glass class="ml-1.5 h-5 w-5 shrink-0 text-ink-soft" aria-hidden="true" />
                <input id="search-q" type="search" name="q" value="{{ $q }}"
                       placeholder="Search products, suppliers, species…"
                       class="min-w-0 flex-1 border-0 bg-transparent p-0 text-[0.9375rem] text-ink placeholder:text-ink-soft/70 focus:outline-none focus:ring-0">

                @if ($q !== '')
                    <a href="{{ route('search') }}" class="rounded-full p-1.5 text-ink-soft transition hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500" aria-label="Clear search">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </a>
                @endif

                {{-- Carry the active filters across a new query. --}}
                @foreach (['type', 'species', 'product_type', 'grade', 'country', 'sort'] as $carry)
                    @if (($filters[$carry] ?? '') !== '')
                        <input type="hidden" name="{{ $carry }}" value="{{ $filters[$carry] }}">
                    @endif
                @endforeach

                <button type="submit" class="rounded-lg bg-forest-800 px-4 py-2 text-[0.875rem] font-semibold text-white transition hover:bg-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500">
                    Search
                </button>
            </form>
        </div>
    </section>

    <div class="mx-auto max-w-[80rem] px-5 pb-14 lg:px-8">

        {{-- ================= RESULT-TYPE TABS ================= --}}
        <nav class="-mx-5 flex gap-2 overflow-x-auto px-5 pt-5 lg:mx-0 lg:px-0" aria-label="Result type">
            @foreach ($typeLabels as $key => $label)
                <a href="{{ $urlWith(['type' => $key, 'page' => null]) }}"
                   @if ($type === $key) aria-current="page" @endif
                   @class([
                       'inline-flex shrink-0 items-center gap-1.5 rounded-full border px-4 py-2 text-[0.875rem] font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500',
                       'border-forest-700 bg-forest-50 text-forest-800' => $type === $key,
                       'border-sand-300 bg-white text-ink-soft hover:border-forest-300 hover:text-forest-700' => $type !== $key,
                   ])>
                    {{ $label }}
                    <span @class([
                        'rounded-full px-1.5 py-0.5 text-[0.6875rem] font-bold',
                        'bg-forest-700 text-white' => $type === $key,
                        'bg-sand-200 text-ink-soft' => $type !== $key,
                    ])>{{ number_format($counts[$key]) }}</span>
                </a>
            @endforeach
        </nav>

        {{-- ================= FACET SELECTS (products only) ================= --}}
        @if ($type === 'products')
            <form method="GET" action="{{ route('search') }}" class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <input type="hidden" name="q" value="{{ $q }}">
                <input type="hidden" name="type" value="products">
                @if ($filters['sort'] !== '')<input type="hidden" name="sort" value="{{ $filters['sort'] }}">@endif

                @foreach ([
                    ['name' => 'species', 'label' => 'All Species', 'options' => $speciesOptions],
                    ['name' => 'product_type', 'label' => 'All Product Types', 'options' => $typeOptions],
                    ['name' => 'grade', 'label' => 'All Grades', 'options' => $gradeOptions],
                    ['name' => 'country', 'label' => 'All Regions', 'options' => $regionOptions],
                ] as $facet)
                    <div>
                        <label for="facet-{{ $facet['name'] }}" class="sr-only">{{ $facet['label'] }}</label>
                        <select id="facet-{{ $facet['name'] }}" name="{{ $facet['name'] }}" onchange="this.form.submit()"
                                class="w-full rounded-lg border border-sand-300 bg-white px-3 py-2.5 text-[0.875rem] font-medium text-ink focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500/30">
                            <option value="">{{ $facet['label'] }}</option>
                            @foreach ($facet['options'] as $value => $optLabel)
                                <option value="{{ $value }}" @selected($filters[$facet['name']] === (string) $value)>{{ $optLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach

                <noscript><button type="submit" class="rounded-lg bg-forest-800 px-4 py-2 text-sm font-semibold text-white">Apply</button></noscript>
            </form>
        @endif

        {{-- ================= COUNT + SORT ================= --}}
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-[0.9375rem] text-ink" aria-live="polite">
                <span class="font-bold">{{ number_format($total) }} {{ Str::plural(Str::singular($typeLabels[$type]), $total) }}</span>
                @if ($type === 'products' && $supplierCount > 0)
                    <span class="text-ink-soft">from {{ number_format($supplierCount) }} {{ Str::plural('supplier', $supplierCount) }}</span>
                @endif
                @if ($activeFilterCount > 0)
                    <a href="{{ route('search', ['q' => $q, 'type' => $type]) }}" class="ml-2 text-[0.8125rem] font-semibold text-forest-700 underline hover:text-forest-800">Clear filters ({{ $activeFilterCount }})</a>
                @endif
            </p>

            @if ($type === 'products')
                <form method="GET" action="{{ route('search') }}" class="flex items-center gap-2">
                    @foreach (['q', 'type', 'species', 'product_type', 'grade', 'country'] as $carry)
                        @if (($filters[$carry] ?? '') !== '')<input type="hidden" name="{{ $carry }}" value="{{ $filters[$carry] }}">@endif
                    @endforeach
                    <label for="sort" class="sr-only">Sort results</label>
                    <select id="sort" name="sort" onchange="this.form.submit()"
                            class="rounded-lg border border-sand-300 bg-white px-3 py-2 text-[0.875rem] font-medium text-ink focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500/30">
                        @foreach ($sortOptions as $value => $optLabel)
                            <option value="{{ $value }}" @selected($filters['sort'] === $value)>Sort by: {{ $optLabel }}</option>
                        @endforeach
                    </select>
                </form>
            @endif
        </div>

        {{-- ================= RESULTS ================= --}}
        @if ($total > 0)
            <div @class([
                'mt-5' => true,
                'space-y-3' => $type === 'products',
                'grid gap-4 sm:grid-cols-2 lg:grid-cols-3' => $type === 'suppliers',
                'grid gap-4 grid-cols-2 sm:grid-cols-3 lg:grid-cols-6' => $type === 'species',
            ])>
                @foreach ($results as $item)
                    @if ($type === 'products')
                        <x-search-product-row :product="$item" />
                    @elseif ($type === 'suppliers')
                        <x-supplier-card :company="$item" />
                    @else
                        <x-species-card :species="$item" />
                    @endif
                @endforeach
            </div>

            <div class="mt-8">
                <x-directory-pagination :paginator="$results" />
            </div>

        {{-- ================= EMPTY STATES ================= --}}
        @elseif ($q !== '')
            <div class="mt-8 rounded-2xl border border-sand-300/70 bg-white p-8 text-center">
                <x-heroicon-o-magnifying-glass class="mx-auto h-10 w-10 text-sand-400" aria-hidden="true" />
                <h2 class="mt-3 font-display text-xl font-semibold text-ink">No {{ strtolower($typeLabels[$type]) }} match “{{ $q }}”</h2>
                <p class="mt-1.5 text-[0.9375rem] text-ink-soft">Try a different spelling, or browse the directories below.</p>

                @foreach ($counts as $key => $count)
                    @if ($key !== $type && $count > 0)
                        <a href="{{ $urlWith(['type' => $key, 'page' => null]) }}" class="mt-3 inline-block text-[0.9375rem] font-semibold text-forest-700 underline hover:text-forest-800">
                            See {{ number_format($count) }} matching {{ strtolower($typeLabels[$key]) }} instead →
                        </a>
                    @endif
                @endforeach

                @if ($suggestions->isNotEmpty())
                    <div class="mt-6">
                        <p class="text-[0.875rem] font-semibold text-ink">Did you mean:</p>
                        <div class="mt-2 flex flex-wrap justify-center gap-2">
                            @foreach ($suggestions as $suggestion)
                                <a href="{{ route('search', ['q' => $suggestion->common_name]) }}"
                                   class="rounded-full border border-sand-300 bg-sand-50 px-3.5 py-1.5 text-[0.875rem] font-medium text-forest-700 transition hover:border-forest-300">
                                    {{ $suggestion->common_name }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="mt-7 flex flex-wrap justify-center gap-3">
                    <a href="{{ route('marketplace') }}" class="rounded-lg bg-forest-800 px-5 py-2.5 text-[0.875rem] font-semibold text-white transition hover:bg-forest-700">Browse marketplace</a>
                    <a href="{{ route('directory') }}" class="rounded-lg border border-sand-300 px-5 py-2.5 text-[0.875rem] font-semibold text-ink transition hover:border-forest-300">All suppliers</a>
                    <a href="{{ route('species.index') }}" class="rounded-lg border border-sand-300 px-5 py-2.5 text-[0.875rem] font-semibold text-ink transition hover:border-forest-300">All species</a>
                </div>
            </div>
        @else
            <div class="mt-8 rounded-2xl border border-sand-300/70 bg-white p-8 text-center">
                <h2 class="font-display text-xl font-semibold text-ink">Start your search</h2>
                <p class="mt-1.5 text-[0.9375rem] text-ink-soft">Search by product, species, grade or supplier name — or browse a directory.</p>
                <div class="mt-6 flex flex-wrap justify-center gap-3">
                    <a href="{{ route('marketplace') }}" class="rounded-lg bg-forest-800 px-5 py-2.5 text-[0.875rem] font-semibold text-white transition hover:bg-forest-700">Browse marketplace</a>
                    <a href="{{ route('directory') }}" class="rounded-lg border border-sand-300 px-5 py-2.5 text-[0.875rem] font-semibold text-ink transition hover:border-forest-300">All suppliers</a>
                    <a href="{{ route('species.index') }}" class="rounded-lg border border-sand-300 px-5 py-2.5 text-[0.875rem] font-semibold text-ink transition hover:border-forest-300">All species</a>
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
