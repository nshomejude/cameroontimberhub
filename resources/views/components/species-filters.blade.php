@props([
    'categoryFacets' => [],
    'propertyFacets' => [],
    'applicationFacets' => [],
    'inStockFacet' => null,
    'regions' => null,
    'facetLimit' => 5,
    'idPrefix' => 'f',
    // Current filter state (read-only here; writes go through wire:model).
    'categories' => [],
    'showApply' => true,
])

@php
    // A stable prefix keeps every label/input `for` pair unique when the panel
    // is rendered twice (desktop rail + mobile drawer) in one document.
    $fid = fn (string $key): string => $idPrefix.'-'.$key;

    $regions = $regions ?? collect();

    $facetSearch = 'w-full rounded-lg border border-sand-300 bg-white py-2 pl-3 pr-9 text-[1.0625rem] text-ink placeholder:text-ink-soft/70 focus:border-forest-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-200';
    $box = 'h-4 w-4 shrink-0 rounded border-sand-400 text-forest-700 accent-forest-700 focus-visible:ring-2 focus-visible:ring-forest-300 focus-visible:ring-offset-1';
    $radio = 'h-4 w-4 shrink-0 border-sand-400 text-forest-700 accent-forest-700 focus-visible:ring-2 focus-visible:ring-forest-300 focus-visible:ring-offset-1';
    $row = 'flex cursor-pointer items-center gap-2.5 py-[0.3125rem] text-[1.0625rem] text-ink';
    $count = 'rounded-full bg-sand-100 px-2 py-0.5 text-[0.875rem] font-semibold tabular-nums text-ink-soft';
@endphp

<div class="flex h-full flex-col">
    <div class="flex-1 space-y-1 overflow-y-auto px-5 py-5">

        <div class="flex items-center justify-between pb-2">
            <h2 class="text-[1.125rem] font-bold text-ink">Filter Species</h2>
            <button type="button" wire:click="resetFilters"
                    class="rounded text-[1.0625rem] font-semibold text-forest-700 transition hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">
                Clear All
            </button>
        </div>

        {{-- Search species --}}
        <div class="pb-4">
            <label for="{{ $fid('search') }}" class="text-[1.0625rem] font-bold text-ink">Search Species</label>
            <div class="relative mt-2.5">
                <input id="{{ $fid('search') }}" type="search" wire:model.live.debounce.400ms="search"
                       placeholder="Search by name or scientific name..." class="{{ $facetSearch }}">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-soft" />
            </div>
        </div>

        <div class="divide-y divide-sand-200 border-t border-sand-200">

            {{-- Category --}}
            @if ($categoryFacets)
                <x-directory-accordion title="Category" :id="$fid('acc-category')" open>
                    <fieldset>
                        <legend class="sr-only">Commercial category</legend>
                        <label class="{{ $row }}" for="{{ $fid('cat-all') }}">
                            <input id="{{ $fid('cat-all') }}" type="checkbox" class="{{ $box }}"
                                   @checked(empty($categories)) wire:click="selectCategory('')">
                            <span class="flex-1">All Categories</span>
                            <span class="{{ $count }}">{{ collect($categoryFacets)->sum('count') }}</span>
                        </label>
                        @foreach ($categoryFacets as $facet)
                            <label class="{{ $row }}" for="{{ $fid('cat-'.$facet['value']) }}">
                                <input id="{{ $fid('cat-'.$facet['value']) }}" type="checkbox" class="{{ $box }}"
                                       value="{{ $facet['value'] }}" wire:model.live="categories">
                                <span class="flex-1">{{ $facet['label'] }}</span>
                                <span class="{{ $count }}">{{ $facet['count'] }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                </x-directory-accordion>
            @endif

            {{-- Properties --}}
            @if ($propertyFacets)
                <x-directory-accordion title="Properties" :id="$fid('acc-properties')" open>
                    <fieldset>
                        <legend class="sr-only">Timber properties</legend>
                        @foreach ($propertyFacets as $facet)
                            <label class="{{ $row }}" for="{{ $fid('prop-'.$facet['value']) }}">
                                <input id="{{ $fid('prop-'.$facet['value']) }}" type="checkbox" class="{{ $box }}"
                                       value="{{ $facet['value'] }}" wire:model.live="properties">
                                <span class="flex-1">{{ $facet['label'] }}</span>
                                <span class="{{ $count }}">{{ $facet['count'] }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                </x-directory-accordion>
            @endif

            {{-- Applications --}}
            @if ($applicationFacets)
                <x-directory-accordion title="Applications" :id="$fid('acc-applications')" open>
                    <fieldset x-data="{ expanded: false }">
                        <legend class="sr-only">Typical applications</legend>
                        @foreach ($applicationFacets as $i => $facet)
                            <label class="{{ $row }}" for="{{ $fid('use-'.$facet['value']) }}"
                                   @if ($i >= $facetLimit) x-show="expanded" x-cloak @endif>
                                <input id="{{ $fid('use-'.$facet['value']) }}" type="checkbox" class="{{ $box }}"
                                       value="{{ $facet['value'] }}" wire:model.live="applications">
                                <span class="flex-1">{{ $facet['label'] }}</span>
                                <span class="{{ $count }}">{{ $facet['count'] }}</span>
                            </label>
                        @endforeach
                        @if (count($applicationFacets) > $facetLimit)
                            <button type="button" @click="expanded = !expanded" :aria-expanded="expanded ? 'true' : 'false'"
                                    class="mt-1 inline-flex items-center gap-1 rounded text-[1.0625rem] font-semibold text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">
                                <span x-text="expanded ? 'View Less' : 'View More'">View More</span>
                                <x-heroicon-m-chevron-down class="h-4 w-4 transition" ::class="expanded && 'rotate-180'" />
                            </button>
                        @endif
                    </fieldset>
                </x-directory-accordion>
            @endif

            {{-- Availability --}}
            @if ($inStockFacet)
                <x-directory-accordion title="Availability" :id="$fid('acc-availability')" open>
                    <label class="{{ $row }}" for="{{ $fid('stock') }}">
                        <input id="{{ $fid('stock') }}" type="checkbox" class="{{ $box }}" wire:model.live="inStock">
                        <span class="flex-1">{{ $inStockFacet['label'] }}</span>
                        <span class="{{ $count }}">{{ $inStockFacet['count'] }}</span>
                    </label>
                </x-directory-accordion>
            @endif

            {{-- Origin region --}}
            @if ($regions->isNotEmpty())
                <x-directory-accordion title="Origin Region" :id="$fid('acc-region')" open>
                    <div class="relative pb-1">
                        <label for="{{ $fid('region') }}" class="sr-only">Origin region</label>
                        <select id="{{ $fid('region') }}" wire:model.live="region"
                                class="w-full appearance-none rounded-lg border border-sand-300 bg-white py-2.5 pl-3 pr-9 text-[1.0625rem] text-ink focus:border-forest-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-200">
                            <option value="">Select Region</option>
                            @foreach ($regions as $r)
                                <option value="{{ $r['value'] }}">{{ $r['value'] }} ({{ $r['count'] }})</option>
                            @endforeach
                        </select>
                        <x-heroicon-m-chevron-down class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-soft" />
                    </div>
                </x-directory-accordion>
            @endif
        </div>
    </div>

    @if ($showApply)
        {{-- Filtering is live, so this is a confirm-and-return affordance: on
             mobile it closes the drawer, on desktop it returns focus to results. --}}
        <div class="border-t border-sand-200 bg-white px-5 py-4">
            <button type="button" @click="$dispatch('close-filter-drawer')"
                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-forest-800 px-5 py-3 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300 focus-visible:ring-offset-2">
                <x-heroicon-o-funnel class="h-4 w-4" />
                Apply Filters
            </button>
        </div>
    @endif
</div>
