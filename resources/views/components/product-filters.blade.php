@props([
    'typeFacets' => [],
    'speciesFacets' => [],
    'regions' => [],
    'flagFacets' => null,
    'facetLimit' => 6,
    'idPrefix' => 'p',
    'types' => [],
    'typeQuery' => '',
    'speciesQuery' => '',
    'showApply' => true,
])

@php
    // A stable prefix keeps every label/input `for` pair unique when the panel
    // is rendered twice (desktop rail + mobile drawer) in one document.
    $fid = fn (string $key): string => $idPrefix.'-'.$key;

    $flagFacets = $flagFacets ?? collect();

    $facetSearch = 'w-full rounded-lg border border-sand-300 bg-white py-2 pl-3 pr-9 text-[1.0625rem] text-ink placeholder:text-ink-soft/70 focus:border-forest-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-200';
    $box = 'h-4 w-4 shrink-0 rounded border-sand-400 text-forest-700 accent-forest-700 focus-visible:ring-2 focus-visible:ring-forest-300 focus-visible:ring-offset-1';
    $radio = 'h-4 w-4 shrink-0 border-sand-400 text-forest-700 accent-forest-700 focus-visible:ring-2 focus-visible:ring-forest-300 focus-visible:ring-offset-1';
    $row = 'flex cursor-pointer items-center gap-2.5 py-[0.3125rem] text-[1.0625rem] text-ink';
    $sectionTitle = 'text-[1.0625rem] font-bold text-ink';

    $matches = fn (array $facet, string $needle): bool => $needle === ''
        || str_contains(strtolower($facet['label']), strtolower($needle));
@endphp

<div class="flex h-full flex-col">
    <div class="flex-1 space-y-6 overflow-y-auto px-5 py-5">

        <div class="flex items-center justify-between">
            <h2 class="text-[1.125rem] font-bold text-ink">Filters</h2>
            <button type="button" wire:click="resetFilters"
                    class="rounded text-[1.0625rem] font-semibold text-forest-700 transition hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">
                Clear All
            </button>
        </div>

        {{-- Free text --}}
        <div>
            <label for="{{ $fid('search') }}" class="{{ $sectionTitle }}">Search Products</label>
            <div class="relative mt-2.5">
                <input id="{{ $fid('search') }}" type="search" wire:model.live.debounce.400ms="search"
                       placeholder="Search by product name..." class="{{ $facetSearch }}">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-soft" />
            </div>
        </div>

        {{-- Product type --}}
        @php($visibleTypes = collect($typeFacets)->filter(fn ($f) => $matches($f, $typeQuery))->values())
        @if ($visibleTypes->isNotEmpty() || $typeQuery !== '')
            <fieldset x-data="{ expanded: false }">
                <legend class="{{ $sectionTitle }}">Product Type</legend>
                <div class="relative mt-2.5">
                    <label class="sr-only" for="{{ $fid('type-q') }}">Search product types</label>
                    <input id="{{ $fid('type-q') }}" type="search" wire:model.live.debounce.300ms="typeQuery"
                           placeholder="Search product types..." class="{{ $facetSearch }}">
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-soft" />
                </div>
                <div class="mt-2">
                    <label class="{{ $row }}" for="{{ $fid('type-all') }}">
                        <input id="{{ $fid('type-all') }}" type="checkbox" class="{{ $box }}"
                               @checked(empty($types)) wire:click="selectType('')">
                        <span class="flex-1">All Products</span>
                    </label>
                    @foreach ($visibleTypes as $i => $facet)
                        <label class="{{ $row }}" for="{{ $fid('type-'.$facet['value']) }}"
                               @if ($i >= $facetLimit) x-show="expanded" x-cloak @endif>
                            <input id="{{ $fid('type-'.$facet['value']) }}" type="checkbox" class="{{ $box }}"
                                   value="{{ $facet['value'] }}" wire:model.live="types">
                            <span class="flex-1">{{ $facet['label'] }}</span>
                            <span class="text-[0.9375rem] tabular-nums text-ink-soft">{{ $facet['count'] }}</span>
                        </label>
                    @endforeach
                </div>
                @if ($visibleTypes->count() > $facetLimit)
                    <button type="button" @click="expanded = !expanded" :aria-expanded="expanded ? 'true' : 'false'"
                            class="mt-1 inline-flex items-center gap-1 rounded text-[1.0625rem] font-medium text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">
                        <span x-text="expanded ? 'Show less' : 'Show more'">Show more</span>
                        <x-heroicon-m-chevron-down class="h-4 w-4 transition" ::class="expanded && 'rotate-180'" />
                    </button>
                @endif
            </fieldset>
        @endif

        {{-- Species --}}
        @php($visibleSpecies = collect($speciesFacets)->filter(fn ($f) => $matches($f, $speciesQuery))->values())
        @if ($visibleSpecies->isNotEmpty() || $speciesQuery !== '')
            <fieldset x-data="{ expanded: false }">
                <legend class="{{ $sectionTitle }}">Wood Species</legend>
                <div class="relative mt-2.5">
                    <label class="sr-only" for="{{ $fid('species-q') }}">Search species</label>
                    <input id="{{ $fid('species-q') }}" type="search" wire:model.live.debounce.300ms="speciesQuery"
                           placeholder="Search species..." class="{{ $facetSearch }}">
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-soft" />
                </div>
                <div class="mt-2">
                    @foreach ($visibleSpecies as $i => $facet)
                        <label class="{{ $row }}" for="{{ $fid('sp-'.$facet['value']) }}"
                               @if ($i >= $facetLimit) x-show="expanded" x-cloak @endif>
                            <input id="{{ $fid('sp-'.$facet['value']) }}" type="checkbox" class="{{ $box }}"
                                   value="{{ $facet['value'] }}" wire:model.live="speciesIn">
                            <span class="flex-1">{{ $facet['label'] }}</span>
                            <span class="text-[0.9375rem] tabular-nums text-ink-soft">{{ $facet['count'] }}</span>
                        </label>
                    @endforeach
                </div>
                @if ($visibleSpecies->count() > $facetLimit)
                    <button type="button" @click="expanded = !expanded" :aria-expanded="expanded ? 'true' : 'false'"
                            class="mt-1 inline-flex items-center gap-1 rounded text-[1.0625rem] font-medium text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">
                        <span x-text="expanded ? 'Show less' : 'Show more'">Show more</span>
                        <x-heroicon-m-chevron-down class="h-4 w-4 transition" ::class="expanded && 'rotate-180'" />
                    </button>
                @endif
            </fieldset>
        @endif

        <div class="divide-y divide-sand-200 border-t border-sand-200">
            @if (! empty($regions))
                <x-directory-accordion title="Supplier Region" :id="$fid('acc-region')">
                    <label class="{{ $row }}" for="{{ $fid('region-any') }}">
                        <input id="{{ $fid('region-any') }}" type="radio" value="" wire:model.live="region" class="{{ $radio }}">
                        <span class="flex-1">All regions</span>
                    </label>
                    @foreach ($regions as $r)
                        <label class="{{ $row }}" for="{{ $fid('region-'.Str::slug($r['value'])) }}">
                            <input id="{{ $fid('region-'.Str::slug($r['value'])) }}" type="radio"
                                   value="{{ $r['value'] }}" wire:model.live="region" class="{{ $radio }}">
                            <span class="flex-1">{{ $r['label'] }}</span>
                            <span class="text-[0.9375rem] tabular-nums text-ink-soft">{{ $r['count'] }}</span>
                        </label>
                    @endforeach
                </x-directory-accordion>
            @endif

            @if (($flagFacets['certifiedOnly'] ?? 0) > 0 || ($flagFacets['bestSellers'] ?? 0) > 0)
                <x-directory-accordion title="Listing" :id="$fid('acc-flags')">
                    @if (($flagFacets['certifiedOnly'] ?? 0) > 0)
                        <label class="{{ $row }}" for="{{ $fid('certified') }}">
                            <input id="{{ $fid('certified') }}" type="checkbox" class="{{ $box }}" wire:model.live="certifiedOnly">
                            <span class="flex-1">Certified origin</span>
                            <span class="text-[0.9375rem] tabular-nums text-ink-soft">{{ $flagFacets['certifiedOnly'] }}</span>
                        </label>
                    @endif
                    @if (($flagFacets['bestSellers'] ?? 0) > 0)
                        <label class="{{ $row }}" for="{{ $fid('best') }}">
                            <input id="{{ $fid('best') }}" type="checkbox" class="{{ $box }}" wire:model.live="bestSellers">
                            <span class="flex-1">Best sellers</span>
                            <span class="text-[0.9375rem] tabular-nums text-ink-soft">{{ $flagFacets['bestSellers'] }}</span>
                        </label>
                    @endif
                </x-directory-accordion>
            @endif
        </div>
    </div>

    @if ($showApply)
        <div class="border-t border-sand-200 bg-white px-5 py-4">
            <button type="button" @click="$dispatch('close-filter-drawer')"
                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-forest-700 px-5 py-3 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300 focus-visible:ring-offset-2">
                <x-heroicon-o-funnel class="h-4 w-4" />
                Apply Filters
            </button>
        </div>
    @endif
</div>
