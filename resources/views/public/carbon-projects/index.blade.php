<x-layouts.app
    title="Carbon Projects — Cameroon carbon-developer projects"
    description="Browse reforestation, afforestation and avoided-deforestation carbon projects listed by verified Cameroon carbon-developer companies."
    :breadcrumbs="$breadcrumbs">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-7xl px-4 py-12">
            <p class="eyebrow">Domestic market</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl">Carbon Projects</h1>
            <p class="mt-3 max-w-2xl text-lg text-ink-soft dark:text-[#b3ab9b]">
                Reforestation, afforestation and avoided-deforestation projects listed by verified
                Cameroon carbon-developer companies.
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

                <form method="GET" action="{{ route('carbon-projects') }}" class="space-y-6 p-4 lg:rounded-2xl lg:border lg:border-sand-200 lg:dark:border-[#2c2a24] lg:bg-white lg:dark:bg-[#1f1d18] lg:p-5">
                    <div>
                        <p class="mb-2 text-sm font-semibold text-ink dark:text-sand-100">Project type</p>
                        <div class="space-y-1.5">
                            @foreach ($projectTypeOptions as $value => $label)
                                <label class="flex items-center gap-2 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">
                                    <input type="checkbox" name="project_type[]" value="{{ $value }}" @checked(in_array($value, $filters['project_type'], true))
                                           class="rounded border-sand-300 text-forest-700 focus:ring-forest-500">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <button type="submit" class="w-full rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">
                        Apply filters
                    </button>

                    @if ($filters['project_type'] !== [])
                        <a href="{{ route('carbon-projects') }}" class="block text-center text-sm font-medium text-ink-soft hover:text-forest-700">Clear filters</a>
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
                @if ($projects->isNotEmpty())
                    <p class="text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]" aria-live="polite">
                        Showing {{ $projects->firstItem() }}–{{ $projects->lastItem() }} of {{ $projects->total() }} projects
                    </p>

                    <div class="mt-5 grid grid-cols-2 gap-6 lg:grid-cols-4">
                        @foreach ($projects as $project)
                            <article class="group relative flex flex-col overflow-hidden rounded-xl border border-sand-300/70 bg-white p-4 transition hover:border-forest-200 hover:shadow-lg dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                                <span class="inline-block w-fit rounded-full bg-forest-100 px-2.5 py-1 text-xs font-semibold text-forest-800">
                                    {{ ucwords(str_replace('_', ' ', $project->project_type)) }}
                                </span>

                                <h3 class="mt-3 text-[1.0625rem] font-bold leading-tight text-ink dark:text-sand-100">
                                    {{ $project->name }}
                                </h3>

                                @if ($project->company)
                                    <a href="{{ route('companies.show', $project->company->slug) }}"
                                       class="relative z-10 mt-1 text-sm text-ink-soft hover:text-forest-700 dark:text-[#b3ab9b]">
                                        {{ $project->company->name }}
                                    </a>
                                @endif

                                @if ($project->region)
                                    <p class="mt-1.5 flex items-center gap-1 text-sm text-ink-soft dark:text-[#b3ab9b]">
                                        <x-heroicon-s-map-pin class="h-3.5 w-3.5 shrink-0 text-forest-600" />
                                        <span class="truncate">{{ $project->region }}</span>
                                    </p>
                                @endif

                                <dl class="mt-3 space-y-1 text-sm text-ink-soft dark:text-[#b3ab9b]">
                                    @if ($project->area_hectares !== null)
                                        <div class="flex justify-between">
                                            <dt>Area</dt>
                                            <dd class="font-semibold text-ink dark:text-sand-100">{{ number_format((float) $project->area_hectares) }} ha</dd>
                                        </div>
                                    @endif
                                    @if ($project->estimated_credits_per_year !== null)
                                        <div class="flex justify-between">
                                            <dt>Est. credits/yr</dt>
                                            <dd class="font-semibold text-ink dark:text-sand-100">{{ number_format((float) $project->estimated_credits_per_year) }}</dd>
                                        </div>
                                    @endif
                                </dl>

                                @if ($project->company)
                                    <a href="{{ route('companies.show', $project->company->slug) }}" class="absolute inset-0" aria-label="View {{ $project->name }}"></a>
                                @endif
                            </article>
                        @endforeach
                    </div>

                    <div class="mt-8">
                        {{ $projects->links() }}
                    </div>
                @else
                    <div class="rounded-2xl border border-dashed border-sand-300 dark:border-[#3a352e] p-10 text-center">
                        <x-heroicon-o-sparkles class="mx-auto h-8 w-8 text-ink-soft" />
                        <p class="mt-3 text-[1.0625rem] font-semibold text-ink dark:text-sand-100">No carbon projects match these filters yet</p>
                        <p class="mt-1 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">Try widening your filters, or check back soon as carbon-developer companies list new projects.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

</x-layouts.app>
