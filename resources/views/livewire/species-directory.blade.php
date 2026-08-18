@php
    $select = 'appearance-none rounded-lg border border-sand-300 bg-white py-2 pl-3 pr-8 text-[0.8125rem] text-ink transition focus:border-forest-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-200';
    $total = $species->total();
@endphp

<div class="bg-white">

    {{-- ==============================================================
         DESKTOP — filter rail + results
    =============================================================== --}}
    <div class="hidden lg:flex lg:items-start">

        <aside class="sticky top-[72px] h-[calc(100vh-72px)] w-[17rem] shrink-0 overflow-hidden border-r border-sand-200 bg-white"
               aria-label="Species filters">
            <div class="flex h-full flex-col">
                {{-- "Browse Categories" — the dark-green rail header from the mockup. --}}
                <div class="px-4 pt-4" x-data="{ open: true }">
                    <button type="button" @click="open = !open" :aria-expanded="open ? 'true' : 'false'"
                            aria-controls="browse-categories-panel"
                            class="flex w-full items-center gap-3 rounded-lg bg-forest-800 px-4 py-3 text-[0.875rem] font-semibold text-white transition hover:bg-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300 focus-visible:ring-offset-2">
                        <x-heroicon-o-bars-3 class="h-5 w-5" />
                        Browse Categories
                        <x-heroicon-m-chevron-down class="ml-auto h-4 w-4 transition" ::class="open && 'rotate-180'" />
                    </button>
                    <ul id="browse-categories-panel" x-show="open" x-cloak class="mt-2 space-y-0.5">
                        @foreach ($categoryFacets as $facet)
                            <li>
                                <button type="button" wire:click="selectCategory('{{ $facet['value'] }}')"
                                        @class([
                                            'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-[0.8125rem] transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300',
                                            'bg-forest-50 font-semibold text-forest-800' => $categories === [$facet['value']],
                                            'text-ink hover:bg-sand-100' => $categories !== [$facet['value']],
                                        ])>
                                    <span class="flex-1">{{ $facet['label'] }}</span>
                                    <span class="text-[0.6875rem] tabular-nums text-ink-soft">{{ $facet['count'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="min-h-0 flex-1">
                    <x-species-filters
                        :category-facets="$categoryFacets" :property-facets="$propertyFacets"
                        :application-facets="$applicationFacets" :in-stock-facet="$inStockFacet"
                        :regions="$regions" :facet-limit="$facetLimit" :categories="$categories"
                        id-prefix="d" />
                </div>
            </div>
        </aside>

        <div class="min-w-0 flex-1 px-6 py-6">

            {{-- Breadcrumb --}}
            <nav aria-label="Breadcrumb">
                <ol class="flex items-center gap-2 text-[0.75rem] text-ink-soft">
                    <li><a href="{{ route('home') }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">Home</a></li>
                    <li aria-hidden="true">/</li>
                    <li><span aria-current="page" class="font-medium text-ink">Timber Species</span></li>
                </ol>
            </nav>

            {{-- Title + stat tile + help panel --}}
            <div class="mt-3 flex items-start gap-6">
                <div class="min-w-0 flex-1">
                    <h1 class="text-[1.875rem] font-bold tracking-tight text-ink">Timber Species Directory</h1>
                    <p class="mt-1.5 max-w-xl text-[0.875rem] leading-relaxed text-ink-soft">
                        Explore verified timber species available in Cameroon. Detailed information to help you make the right choice.
                    </p>
                </div>

                <div class="flex min-w-[10rem] shrink-0 items-center gap-3 rounded-xl border border-sand-300/70 bg-white px-5 py-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-forest-50 text-forest-700">
                        <x-heroicon-o-sparkles class="h-6 w-6" />
                    </span>
                    <div>
                        <p class="text-[1.5rem] font-bold leading-none text-ink">{{ $publishedCount }}</p>
                        <p class="mt-1 text-[0.75rem] text-ink-soft">Timber Species</p>
                    </div>
                </div>

                <div class="relative w-[20rem] shrink-0 overflow-hidden rounded-xl bg-forest-800 px-5 py-4 text-white">
                    <x-heroicon-o-lifebuoy class="pointer-events-none absolute -right-3 top-1/2 h-24 w-24 -translate-y-1/2 text-white/10" aria-hidden="true" />
                    <p class="text-[1rem] font-bold">Need help choosing?</p>
                    <p class="mt-1 text-[0.8125rem] text-forest-100">Talk to our timber experts</p>
                    <a href="{{ route('contact') }}"
                       class="mt-3 inline-flex rounded-lg bg-white px-4 py-2 text-[0.8125rem] font-semibold text-forest-800 transition hover:bg-forest-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-forest-800">
                        Contact Us
                    </a>
                </div>
            </div>

            {{-- Toolbar --}}
            <div class="mt-6 flex items-center gap-3">
                <p class="text-[0.875rem] text-ink-soft">
                    @if ($total > 0)
                        Showing {{ $species->firstItem() }} – {{ $species->lastItem() }} of {{ $total }} species
                    @else
                        No species match these filters
                    @endif
                </p>

                <div class="ml-auto flex items-center gap-3">
                    <div class="inline-flex rounded-lg border border-sand-300 p-0.5" role="group" aria-label="Result layout">
                        @foreach ([['grid', 'Grid view', 'squares-2x2'], ['list', 'List view', 'bars-3']] as [$mode, $label, $icon])
                            <button type="button" wire:click="setView('{{ $mode }}')"
                                    aria-pressed="{{ $view === $mode ? 'true' : 'false' }}"
                                    aria-label="{{ $label }}"
                                    @class([
                                        'flex h-8 w-9 items-center justify-center rounded-md transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500',
                                        'bg-forest-700 text-white' => $view === $mode,
                                        'text-ink-soft hover:text-forest-700' => $view !== $mode,
                                    ])>
                                <x-dynamic-component :component="'heroicon-m-'.$icon" class="h-4 w-4" />
                            </button>
                        @endforeach
                    </div>

                    <div class="relative">
                        <label for="d-sort" class="sr-only">Sort species by</label>
                        <select id="d-sort" wire:model.live="sort" class="{{ $select }}">
                            @foreach ($sortOptions as $value => $label)
                                <option value="{{ $value }}">Sort by: {{ $label }}</option>
                            @endforeach
                        </select>
                        <x-heroicon-m-chevron-down class="pointer-events-none absolute right-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-soft" />
                    </div>
                </div>
            </div>

            {{-- Results --}}
            <div wire:loading.class="opacity-60" class="mt-5 transition-opacity">
                @if ($species->isNotEmpty())
                    @if ($view === 'list')
                        <div class="space-y-3">
                            @foreach ($species as $sp)
                                <x-species-row :species="$sp" />
                            @endforeach
                        </div>
                    @else
                        <div class="grid grid-cols-3 gap-5 xl:grid-cols-4 2xl:grid-cols-6">
                            @foreach ($species as $sp)
                                <x-species-card :species="$sp" />
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-8 flex flex-col items-center gap-3 sm:flex-row">
                        <div class="flex-1"><x-directory-pagination :paginator="$species" noun="species" /></div>
                        <div class="relative flex items-center gap-2">
                            <label for="d-per-page" class="text-[0.8125rem] text-ink-soft">Show</label>
                            <select id="d-per-page" wire:model.live="perPage" class="{{ $select }}">
                                @foreach ([12, 24, 48] as $n)
                                    <option value="{{ $n }}">{{ $n }}</option>
                                @endforeach
                            </select>
                            <span class="text-[0.8125rem] text-ink-soft">per page</span>
                        </div>
                    </div>
                @else
                    <x-species-empty />
                @endif
            </div>
        </div>
    </div>

    {{-- ==============================================================
         MOBILE
    =============================================================== --}}
    <div x-data="{ drawer: false }" @close-filter-drawer.window="drawer = false" class="lg:hidden">
        <div class="px-4 pt-4">
            <nav aria-label="Breadcrumb">
                <ol class="flex items-center gap-2 text-[0.8125rem] text-ink-soft">
                    <li><a href="{{ route('home') }}" class="rounded transition hover:text-forest-700">Home</a></li>
                    <li aria-hidden="true">›</li>
                    <li><span aria-current="page" class="text-ink">Timber Species</span></li>
                </ol>
            </nav>

            <h1 class="mt-2 text-[1.75rem] font-bold leading-tight tracking-tight text-ink">Timber Species Directory</h1>
            <p class="mt-2 text-[0.9375rem] leading-relaxed text-ink-soft">
                Explore our comprehensive directory of timber species sourced from Cameroon's rich forests.
            </p>

            {{-- Search + filter trigger --}}
            <div class="mt-4 flex gap-3">
                <div class="relative flex-1">
                    <label for="m-search" class="sr-only">Search species</label>
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-soft" />
                    <input id="m-search" type="search" wire:model.live.debounce.400ms="search"
                           placeholder="Search species..."
                           class="w-full rounded-xl border border-sand-300 bg-white py-3 pl-11 pr-3 text-[0.9375rem] text-ink placeholder:text-ink-soft/70 focus:border-forest-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-200">
                </div>
                <button type="button" @click="drawer = true"
                        class="flex shrink-0 items-center gap-2 rounded-xl border border-sand-300 px-5 text-[0.9375rem] font-semibold text-ink transition hover:border-forest-600 hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                    <x-heroicon-o-funnel class="h-5 w-5" />
                    Filter
                </button>
            </div>
        </div>

        {{-- Category chip row --}}
        <div class="mt-4 flex gap-2.5 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <button type="button" wire:click="selectCategory('')"
                    aria-pressed="{{ $categories === [] ? 'true' : 'false' }}"
                    @class([
                        'inline-flex shrink-0 items-center gap-2 rounded-full border px-4 py-2.5 text-[0.875rem] font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500',
                        'border-forest-700 bg-forest-50 text-forest-800' => $categories === [],
                        'border-sand-300 text-ink' => $categories !== [],
                    ])>
                All Species
            </button>
            @foreach ($categoryFacets as $facet)
                <button type="button" wire:click="selectCategory('{{ $facet['value'] }}')"
                        aria-pressed="{{ $categories === [$facet['value']] ? 'true' : 'false' }}"
                        @class([
                            'inline-flex shrink-0 items-center gap-2 rounded-full border px-4 py-2.5 text-[0.875rem] font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500',
                            'border-forest-700 bg-forest-50 text-forest-800' => $categories === [$facet['value']],
                            'border-sand-300 text-ink' => $categories !== [$facet['value']],
                        ])>
                    {{ $facet['label'] }}
                    <span class="text-[0.75rem] font-normal text-ink-soft">{{ $facet['count'] }}</span>
                </button>
            @endforeach
        </div>

        {{-- Count + sort --}}
        <div class="mt-4 flex items-center gap-3 px-4">
            <p class="text-[0.9375rem] text-ink">{{ $total }} Species Found</p>
            <div class="relative ml-auto">
                <label for="m-sort" class="sr-only">Sort species by</label>
                <select id="m-sort" wire:model.live="sort"
                        class="appearance-none rounded-xl border border-sand-300 bg-white py-3 pl-4 pr-10 text-[0.9375rem] text-ink focus:border-forest-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-200">
                    @foreach ($sortOptions as $value => $label)
                        <option value="{{ $value }}">Sort: {{ $label }}</option>
                    @endforeach
                </select>
                <x-heroicon-m-chevron-down class="pointer-events-none absolute right-3 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-soft" />
            </div>
        </div>

        {{-- 2-column grid --}}
        <div wire:loading.class="opacity-60" class="mt-4 px-4 transition-opacity">
            @if ($species->isNotEmpty())
                <div class="grid grid-cols-2 gap-4">
                    @foreach ($species as $sp)
                        <x-species-card :species="$sp" compact />
                    @endforeach
                </div>
                <div class="mt-6">
                    <x-directory-pagination :paginator="$species" noun="species" />
                </div>
            @else
                <x-species-empty />
            @endif
        </div>

        {{-- "Can't find the species you need?" --}}
        <div class="mx-4 mt-6 mb-8 flex items-center gap-3 rounded-xl border border-forest-100 bg-forest-50 p-4">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white text-forest-700">
                <x-heroicon-o-sparkles class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-[0.9375rem] font-bold text-ink">Can't find the species you need?</p>
                <p class="mt-0.5 text-[0.8125rem] text-ink-soft">Submit a request and we'll help you source it.</p>
            </div>
            <a href="{{ route('rfq.create') }}"
               class="shrink-0 rounded-lg bg-forest-700 px-4 py-2.5 text-[0.875rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                Request Species
            </a>
        </div>

        {{-- Filter drawer --}}
        <div x-show="drawer" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="Species filters"
             @keydown.escape.window="drawer = false">
            <div class="absolute inset-0 bg-black/40" @click="drawer = false"></div>
            <div x-show="drawer"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
                 class="absolute inset-x-0 bottom-0 flex max-h-[88vh] flex-col rounded-t-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-sand-200 px-5 py-3">
                    <span class="text-[0.9375rem] font-bold text-ink">Filter species</span>
                    <button type="button" @click="drawer = false"
                            class="flex h-9 w-9 items-center justify-center rounded-lg text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500"
                            aria-label="Close filters">
                        <x-heroicon-o-x-mark class="h-6 w-6" />
                    </button>
                </div>
                <div class="min-h-0 flex-1">
                    <x-species-filters
                        :category-facets="$categoryFacets" :property-facets="$propertyFacets"
                        :application-facets="$applicationFacets" :in-stock-facet="$inStockFacet"
                        :regions="$regions" :facet-limit="$facetLimit" :categories="$categories"
                        id-prefix="m" />
                </div>
            </div>
        </div>
    </div>
</div>
