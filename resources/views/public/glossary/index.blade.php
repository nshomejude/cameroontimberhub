<x-layouts.app
    title="Cameroon Timber Glossary"
    description="Definitions for Cameroon timber trade terms — grading, shipping, compliance and species terminology explained."
    :breadcrumbs="$breadcrumbs"
    :schema="$schema">

    <div class="bg-white">
        <div class="mx-auto max-w-4xl px-4 py-8 lg:px-6">

            <nav aria-label="Breadcrumb">
                <ol class="flex flex-wrap items-center gap-2 text-[0.9375rem] text-ink-soft">
                    <li><a href="{{ route('home') }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">Home</a></li>
                    <li aria-hidden="true">/</li>
                    <li><span aria-current="page" class="font-medium text-ink">Glossary</span></li>
                </ol>
            </nav>

            <p class="eyebrow mt-3">Cameroon Timber Hub Knowledge</p>
            <h1 class="mt-1 text-[1.875rem] font-bold tracking-tight text-ink lg:text-[2.125rem]">Cameroon Timber Glossary</h1>
            <p class="mt-1.5 max-w-2xl text-[1.125rem] leading-relaxed text-ink-soft">
                The vocabulary of the Cameroon timber trade — grading, drying, measurement, shipping and compliance terms,
                defined plainly. {{ $total }} {{ Str::plural('term', $total) }} defined.
            </p>

            {{-- A plain GET form: no JavaScript needed, every filtered view is a real, shareable URL. --}}
            <form method="GET" action="{{ route('glossary.index') }}" class="mt-6">
                <div class="flex flex-col gap-3 sm:flex-row">
                    <label class="sr-only" for="glossary-q">Search the glossary</label>
                    <div class="relative flex-1">
                        <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-soft" />
                        <input id="glossary-q" type="search" name="q" value="{{ $q }}"
                               placeholder="Search timber terms — CBM, FOB, kiln dried…"
                               class="w-full rounded-lg border border-sand-300 bg-white py-2.5 pl-10 pr-3 text-[1.0625rem] text-ink transition placeholder:text-ink-soft focus:border-forest-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-200">
                    </div>
                    <button type="submit"
                            class="rounded-lg bg-forest-700 px-5 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                        Search
                    </button>
                </div>
            </form>

            @forelse ($terms as $letter => $group)
                <section class="mt-8">
                    <h2 class="text-[1.25rem] font-bold text-forest-800">{{ $letter }}</h2>
                    <ul class="mt-3 divide-y divide-sand-200 border-t border-sand-200">
                        @foreach ($group as $entry)
                            <li class="py-3">
                                <a href="{{ $entry->url() }}"
                                   class="text-[1.0625rem] font-semibold text-forest-700 transition hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">{{ $entry->term }}</a>
                                <p class="mt-1 text-[1rem] leading-relaxed text-ink-soft">{{ Str::limit($entry->definition, 140) }}</p>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
                <div class="mt-8 rounded-xl border border-dashed border-sand-300 bg-sand-50 px-6 py-12 text-center">
                    <h2 class="text-[1.25rem] font-bold text-ink">No terms found</h2>
                    <p class="mx-auto mt-2 max-w-md text-[1.0625rem] text-ink-soft">
                        @if ($q !== '')
                            Nothing matched “{{ $q }}”. Try a broader term, or browse the whole glossary.
                        @else
                            The glossary is being written. In the meantime, browse the species directory or our insights.
                        @endif
                    </p>
                    @if ($q !== '')
                        <div class="mt-5">
                            <a href="{{ route('glossary.index') }}"
                               class="inline-flex rounded-lg bg-forest-700 px-5 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800">Browse all terms</a>
                        </div>
                    @endif
                </div>
            @endforelse
        </div>
    </div>
</x-layouts.app>
