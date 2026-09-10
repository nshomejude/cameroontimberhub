@php
    $select = 'appearance-none rounded-lg border border-sand-300 bg-white py-2 pl-3 pr-8 text-[1.0625rem] text-ink transition focus:border-forest-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-200';
    $total = $companies->total();
@endphp

<div class="bg-white">

    {{-- ==============================================================
         DESKTOP — filter rail + results
    =============================================================== --}}
    <div class="hidden lg:flex lg:items-start">

        {{-- Filter rail --}}
        <aside class="sticky top-[72px] h-[calc(100vh-72px)] w-[17rem] shrink-0 border-r border-sand-200 bg-white"
               aria-label="Supplier filters">
            <x-directory-filters
                :type-facets="$typeFacets" :spec-facets="$specFacets" :species-facets="$speciesFacets"
                :cert-facets="$certFacets" :experience-facets="$experienceFacets" :regions="$regions"
                :facet-limit="$facetLimit" :types="$types" :spec-query="$specQuery" :species-query="$speciesQuery"
                id-prefix="d" />
        </aside>

        <div class="min-w-0 flex-1 px-6 py-6">

            {{-- Breadcrumb --}}
            <nav aria-label="Breadcrumb">
                <ol class="flex items-center gap-2 text-[0.9375rem] text-ink-soft">
                    <li><a href="{{ route('home') }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">{{ __('messages.common.home') }}</a></li>
                    <li aria-hidden="true">/</li>
                    <li><span aria-current="page" class="font-medium text-ink">{{ __('messages.common.suppliers') }}</span></li>
                </ol>
            </nav>

            {{-- Title + stat tiles --}}
            <div class="mt-3 flex items-start gap-8">
                <div class="min-w-0 flex-1">
                    <h1 class="text-[1.875rem] font-bold tracking-tight text-ink">{{ __('messages.directory.title') }}</h1>
                    <p class="mt-1.5 text-[1.0625rem] text-ink-soft">
                        {{ __('messages.directory.intro') }}
                    </p>
                </div>

                <dl class="grid shrink-0 gap-4" style="grid-template-columns: repeat({{ count($stats) }}, minmax(0, 1fr));">
                    @foreach ($stats as $stat)
                        <div class="flex min-w-[11rem] items-center gap-3 rounded-xl border border-sand-300/70 bg-white px-4 py-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-forest-50 text-forest-700">
                                <x-dynamic-component :component="'heroicon-o-'.$stat['icon']" class="h-5 w-5" />
                            </span>
                            <div class="min-w-0">
                                <dd class="text-[1.125rem] font-bold leading-tight text-forest-800">{{ $stat['value'] }}</dd>
                                <dt class="truncate text-[0.9375rem] text-ink-soft">{{ $stat['label'] }}</dt>
                            </div>
                        </div>
                    @endforeach
                </dl>
            </div>

            {{-- Toolbar --}}
            <div class="mt-6 flex items-center gap-3">
                <div class="inline-flex rounded-lg border border-sand-300 p-0.5" role="group" aria-label="Result layout">
                    @foreach ([['grid', __('messages.common.grid_view'), 'squares-2x2'], ['list', __('messages.common.list_view'), 'bars-3']] as [$mode, $label, $icon])
                        <button type="button" wire:click="setView('{{ $mode }}')"
                                aria-pressed="{{ $view === $mode ? 'true' : 'false' }}"
                                @class([
                                    'inline-flex items-center gap-2 rounded-md px-4 py-2 text-[1.0625rem] font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500',
                                    'bg-forest-700 text-white' => $view === $mode,
                                    'text-ink-soft hover:text-forest-700' => $view !== $mode,
                                ])>
                            <x-dynamic-component :component="'heroicon-m-'.$icon" class="h-4 w-4" />
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <div class="ml-auto flex items-center gap-3">
                    <div class="relative">
                        <label for="d-sort" class="sr-only">{{ __('messages.directory.title') }}</label>
                        <select id="d-sort" wire:model.live="sort" class="{{ $select }}">
                            @foreach ($sortOptions as $value => $label)
                                <option value="{{ $value }}">{{ __('messages.common.sort_by', ['label' => $label]) }}</option>
                            @endforeach
                        </select>
                        <x-heroicon-m-chevron-down class="pointer-events-none absolute right-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-soft" />
                    </div>
                    <div class="relative">
                        <label for="d-per-page" class="sr-only">{{ __('messages.common.per_page') }}</label>
                        <select id="d-per-page" wire:model.live="perPage" class="{{ $select }}">
                            @foreach ([12, 24, 48] as $n)
                                <option value="{{ $n }}">{{ __('messages.common.show_count', ['count' => $n]) }}</option>
                            @endforeach
                        </select>
                        <x-heroicon-m-chevron-down class="pointer-events-none absolute right-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-soft" />
                    </div>
                </div>
            </div>

            {{-- Results --}}
            <div wire:loading.class="opacity-60" class="mt-5 transition-opacity">
                @if ($companies->isNotEmpty())
                    @if ($view === 'list')
                        <div class="space-y-3">
                            @foreach ($companies as $company)
                                <x-supplier-row :company="$company" />
                            @endforeach
                        </div>
                    @else
                        <div class="grid grid-cols-2 gap-5 xl:grid-cols-3 2xl:grid-cols-4">
                            @foreach ($companies as $company)
                                <x-supplier-card :company="$company" />
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-8">
                        <x-directory-pagination :paginator="$companies" :noun="__('messages.common.noun_suppliers')" />
                    </div>
                @else
                    <x-directory-empty />
                @endif
            </div>
        </div>
    </div>

    {{-- ==============================================================
         MOBILE
    =============================================================== --}}
    <div x-data="{ drawer: false }" @close-filter-drawer.window="drawer = false" class="lg:hidden">
        <div class="px-4 pt-5">
            <h2 class="text-[1.75rem] font-bold leading-tight tracking-tight text-ink">{{ __('messages.directory.title') }}</h2>
            <p class="mt-2 text-[1.125rem] leading-relaxed text-ink-soft">
                {{ __('messages.directory.intro_mobile') }}
            </p>

            {{-- Search + filter trigger --}}
            <div class="mt-4 flex gap-3">
                <div class="relative flex-1">
                    <label for="m-search" class="sr-only">{{ __('messages.common.search') }}</label>
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-soft" />
                    <input id="m-search" type="search" wire:model.live.debounce.400ms="search"
                           placeholder="{{ __('messages.directory.search_suppliers_mobile') }}"
                           class="w-full rounded-xl border border-sand-300 bg-white py-3 pl-11 pr-3 text-[1.125rem] text-ink placeholder:text-ink-soft/70 focus:border-forest-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-200">
                </div>
                <button type="button" @click="drawer = true"
                        class="flex shrink-0 items-center gap-2 rounded-xl bg-forest-800 px-5 text-[1.125rem] font-semibold text-white transition hover:bg-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                    <x-heroicon-o-adjustments-horizontal class="h-5 w-5" />
                    {{ __('messages.common.filters') }}
                </button>
            </div>
        </div>

        {{-- Type chip row --}}
        <div class="mt-4 flex gap-2.5 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <button type="button" wire:click="selectType('')"
                    aria-pressed="{{ $types === [] ? 'true' : 'false' }}"
                    @class([
                        'inline-flex shrink-0 items-center gap-2 rounded-full border px-4 py-2.5 text-[1.0625rem] font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500',
                        'border-forest-700 text-forest-700' => $types === [],
                        'border-sand-300 text-ink' => $types !== [],
                    ])>
                <x-heroicon-o-adjustments-horizontal class="h-5 w-5" />
                {{ __('messages.directory.all_suppliers') }}
            </button>
            @foreach ($typeFacets as $facet)
                <button type="button" wire:click="selectType('{{ $facet['value'] }}')"
                        aria-pressed="{{ $types === [$facet['value']] ? 'true' : 'false' }}"
                        @class([
                            'inline-flex shrink-0 items-center gap-2 rounded-full border px-4 py-2.5 text-[1.0625rem] font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500',
                            'border-forest-700 text-forest-700' => $types === [$facet['value']],
                            'border-sand-300 text-ink' => $types !== [$facet['value']],
                        ])>
                    <x-dynamic-component :component="'heroicon-o-'.$facet['icon']" class="h-5 w-5" />
                    {{ $facet['plural'] }}
                </button>
            @endforeach
        </div>

        {{-- Count + sort --}}
        <div class="mt-4 flex items-center gap-3 px-4">
            <p class="text-[1.125rem] text-ink">{{ __('messages.directory.suppliers_found', ['count' => $total]) }}</p>
            <div class="relative ml-auto">
                <label for="m-sort" class="sr-only">{{ __('messages.common.sort_by', ['label' => '']) }}</label>
                <select id="m-sort" wire:model.live="sort"
                        class="appearance-none rounded-xl border border-sand-300 bg-white py-3 pl-4 pr-10 text-[1.125rem] text-ink focus:border-forest-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-200">
                    @foreach ($sortOptions as $value => $label)
                        <option value="{{ $value }}">{{ __('messages.common.sort_short', ['label' => $label]) }}</option>
                    @endforeach
                </select>
                <x-heroicon-m-chevron-down class="pointer-events-none absolute right-3 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-soft" />
            </div>
        </div>

        {{-- 2-column grid --}}
        <div wire:loading.class="opacity-60" class="mt-4 px-4 pb-8 transition-opacity">
            @if ($companies->isNotEmpty())
                <div class="grid grid-cols-2 gap-4">
                    @foreach ($companies as $company)
                        <x-supplier-card :company="$company" compact />
                    @endforeach
                </div>
                <div class="mt-6">
                    <x-directory-pagination :paginator="$companies" :noun="__('messages.common.noun_suppliers')" />
                </div>
            @else
                <x-directory-empty />
            @endif
        </div>

        {{-- Filter drawer --}}
        <div x-show="drawer" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="Supplier filters"
             @keydown.escape.window="drawer = false">
            <div class="absolute inset-0 bg-black/40" @click="drawer = false"></div>
            <div x-show="drawer"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
                 class="absolute inset-x-0 bottom-0 flex max-h-[88vh] flex-col rounded-t-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-sand-200 px-5 py-3">
                    <span class="text-[1.125rem] font-bold text-ink">{{ __('messages.directory.filter_suppliers') }}</span>
                    <button type="button" @click="drawer = false"
                            class="flex h-9 w-9 items-center justify-center rounded-lg text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500"
                            aria-label="Close filters">
                        <x-heroicon-o-x-mark class="h-6 w-6" />
                    </button>
                </div>
                <div class="min-h-0 flex-1">
                    <x-directory-filters
                        :type-facets="$typeFacets" :spec-facets="$specFacets" :species-facets="$speciesFacets"
                        :cert-facets="$certFacets" :experience-facets="$experienceFacets" :regions="$regions"
                        :facet-limit="$facetLimit" :types="$types" :spec-query="$specQuery" :species-query="$speciesQuery"
                        id-prefix="m" />
                </div>
            </div>
        </div>
    </div>
</div>
