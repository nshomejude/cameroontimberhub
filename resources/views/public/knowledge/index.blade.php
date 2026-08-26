<x-layouts.app
    title="Cameroon Timber Knowledge Centre"
    description="The Cameroon timber knowledge base — fundamentals, products, processing, grading, buying, export, compliance, sustainability, logistics and the business of the trade."
    :breadcrumbs="$breadcrumbs"
    :schema="$schema">

    <div class="bg-white">
        <div class="mx-auto max-w-5xl px-4 py-8 lg:px-6">

            <nav aria-label="Breadcrumb">
                <ol class="flex flex-wrap items-center gap-2 text-[0.9375rem] text-ink-soft">
                    <li><a href="{{ route('home') }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">Home</a></li>
                    <li aria-hidden="true">/</li>
                    <li><span aria-current="page" class="font-medium text-ink">Knowledge Centre</span></li>
                </ol>
            </nav>

            <p class="eyebrow mt-3">Cameroon Timber Hub Knowledge</p>
            <h1 class="mt-1 text-[1.875rem] font-bold tracking-tight text-ink lg:text-[2.125rem]">Knowledge Centre</h1>
            <p class="mt-1.5 max-w-2xl text-[1.125rem] leading-relaxed text-ink-soft">
                Everything we know about buying, exporting and shipping Cameroonian timber, organised into
                {{ count($hubs) }} subject hubs. Each hub collects the guides and explainers for one part of the trade.
            </p>

            <ul class="mt-8 grid gap-4 sm:grid-cols-2">
                @foreach ($hubs as $entry)
                    @php($count = (int) ($counts[$entry->value] ?? 0))
                    <li class="group relative flex flex-col rounded-xl border border-sand-300/70 bg-white px-5 py-4 transition hover:border-forest-200 hover:shadow-lg">
                        <h2 class="text-[1.125rem] font-bold leading-snug text-ink">
                            <a href="{{ $entry->url() }}"
                               class="rounded transition after:absolute after:inset-0 hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500">{{ $entry->label() }}</a>
                        </h2>
                        <p class="mt-2 text-[1rem] leading-relaxed text-ink-soft">{{ $entry->description() }}</p>
                        {{-- A hub with nothing published yet shows no count at all: an
                             honest absence beats an advertised zero. --}}
                        @if ($count > 0)
                            <p class="mt-3 text-[0.9375rem] font-semibold text-forest-700">
                                {{ $count }} {{ Str::plural('article', $count) }}
                            </p>
                        @endif
                    </li>
                @endforeach
            </ul>

            <div class="mt-8 border-t border-sand-200 pt-6">
                <p class="text-[1.0625rem] text-ink-soft">
                    Looking for a single term? Browse the
                    <a href="{{ route('glossary.index') }}" class="font-semibold text-forest-700 transition hover:underline">Cameroon timber glossary</a>,
                    or read the latest <a href="{{ route('insights.index') }}" class="font-semibold text-forest-700 transition hover:underline">market insights</a>.
                </p>
            </div>
        </div>
    </div>
</x-layouts.app>
