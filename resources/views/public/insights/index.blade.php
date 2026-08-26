@php
    use App\Enums\ArticleCategory;

    $pageTitle = $category
        ? $category->label().' — Cameroon timber insights'
        : 'Timber Insights — guides, market data and export compliance';

    $pageDescription = $category
        ? $category->description()
        : 'Guides, market insight and compliance explainers for buyers and exporters of Cameroonian timber — grading, legality, freight, pricing and supplier due diligence.';

    $featured = ! $category && $q === '' && $articles->currentPage() === 1 ? $articles->first() : null;
    $grid = $featured ? $articles->slice(1) : $articles->getCollection();
@endphp

<x-layouts.app
    :title="$pageTitle"
    :description="$pageDescription"
    :breadcrumbs="$breadcrumbs"
    :schema="$schema">

    <div class="bg-white">
        <div class="mx-auto max-w-[1400px] px-4 py-6 lg:px-6">

            {{-- Breadcrumb --}}
            <nav aria-label="Breadcrumb">
                <ol class="flex flex-wrap items-center gap-2 text-[0.9375rem] text-ink-soft">
                    <li><a href="{{ route('home') }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">Home</a></li>
                    <li aria-hidden="true">/</li>
                    @if ($category)
                        <li><a href="{{ route('insights.index') }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">Insights</a></li>
                        <li aria-hidden="true">/</li>
                        <li><span aria-current="page" class="font-medium text-ink">{{ $category->label() }}</span></li>
                    @else
                        <li><span aria-current="page" class="font-medium text-ink">Insights</span></li>
                    @endif
                </ol>
            </nav>

            {{-- Title + counter --}}
            <div class="mt-3 flex flex-col gap-5 lg:flex-row lg:items-start lg:gap-6">
                <div class="min-w-0 flex-1">
                    <p class="eyebrow">Cameroon Timber Hub Insights</p>
                    <h1 class="mt-1 text-[1.875rem] font-bold tracking-tight text-ink lg:text-[2.125rem]">
                        {{ $category ? $category->label() : 'Timber Insights' }}
                    </h1>
                    <p class="mt-1.5 max-w-2xl text-[1.125rem] leading-relaxed text-ink-soft">
                        {{ $category ? $category->description() : 'Practical writing for people who actually buy timber — how it is graded, what the paperwork demands, what it costs to move, and who to buy it from.' }}
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-3 rounded-xl border border-sand-300/70 bg-white px-5 py-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-forest-50 text-forest-700">
                        <x-heroicon-o-book-open class="h-6 w-6" />
                    </span>
                    <div>
                        <p class="text-[1.5rem] font-bold leading-none text-ink">{{ $total }}</p>
                        <p class="mt-1 text-[0.9375rem] text-ink-soft">{{ Str::plural('Article', $total) }} published</p>
                    </div>
                </div>
            </div>

            {{-- Search + category filter. A plain GET form: no JavaScript needed,
                 every filtered view is a real, shareable, crawlable URL. --}}
            <form method="GET" action="{{ route('insights.index') }}" class="mt-6">
                <div class="flex flex-col gap-3 sm:flex-row">
                    <label class="sr-only" for="insights-q">Search articles</label>
                    <div class="relative flex-1">
                        <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-soft" />
                        <input id="insights-q" type="search" name="q" value="{{ $q }}"
                               placeholder="Search insights — grading, EUDR, freight, prices…"
                               class="w-full rounded-lg border border-sand-300 bg-white py-2.5 pl-10 pr-3 text-[1.0625rem] text-ink transition placeholder:text-ink-soft focus:border-forest-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-200">
                    </div>
                    @if ($category)
                        <input type="hidden" name="category" value="{{ $category->value }}">
                    @endif
                    <button type="submit"
                            class="rounded-lg bg-forest-700 px-5 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                        Search
                    </button>
                </div>
            </form>

            <nav aria-label="Article categories" class="mt-4 flex flex-wrap gap-2">
                @php $pill = 'rounded-lg border px-3.5 py-2 text-[1.0625rem] font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300'; @endphp
                <a href="{{ route('insights.index') }}"
                   @class([$pill, 'border-forest-700 bg-forest-700 text-white' => ! $category, 'border-sand-300 text-ink hover:border-forest-600 hover:text-forest-700' => (bool) $category])>
                    All <span class="tabular-nums opacity-70">{{ $total }}</span>
                </a>
                @foreach (ArticleCategory::cases() as $case)
                    @php $count = (int) ($counts[$case->value] ?? 0); @endphp
                    <a href="{{ route('insights.category', $case->value) }}"
                       @class([$pill, 'border-forest-700 bg-forest-700 text-white' => $category === $case, 'border-sand-300 text-ink hover:border-forest-600 hover:text-forest-700' => $category !== $case])>
                        {{ $case->label() }} <span class="tabular-nums opacity-70">{{ $count }}</span>
                    </a>
                @endforeach
            </nav>

            {{-- Results --}}
            @if ($articles->total() === 0)
                <div class="mt-8 rounded-xl border border-dashed border-sand-300 bg-sand-50 px-6 py-12 text-center">
                    <h2 class="text-[1.25rem] font-bold text-ink">No articles yet</h2>
                    <p class="mx-auto mt-2 max-w-md text-[1.0625rem] text-ink-soft">
                        @if ($q !== '')
                            Nothing matched “{{ $q }}”. Try a broader term, or browse every article.
                        @else
                            This section is being written. In the meantime, browse the species directory or talk to a supplier directly.
                        @endif
                    </p>
                    <div class="mt-5 flex flex-wrap justify-center gap-3">
                        <a href="{{ route('insights.index') }}" class="rounded-lg border border-sand-300 px-4 py-2 text-[1.0625rem] font-semibold text-ink transition hover:border-forest-600 hover:text-forest-700">All insights</a>
                        <a href="{{ route('species.index') }}" class="rounded-lg bg-forest-700 px-4 py-2 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800">Browse species</a>
                    </div>
                </div>
            @else
                @if ($featured)
                    <div class="mt-7">
                        <x-article-card :article="$featured" featured />
                    </div>
                @endif

                <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($grid as $article)
                        <x-article-card :article="$article" />
                    @endforeach
                </div>

                <div class="mt-8 flex flex-col items-center gap-3 sm:flex-row">
                    {{ $articles->onEachSide(1)->links() }}
                    <p class="text-[1.0625rem] text-ink-soft sm:ml-auto">
                        Showing {{ $articles->firstItem() }} to {{ $articles->lastItem() }} of {{ $articles->total() }} articles
                    </p>
                </div>
            @endif

            {{-- Funnel CTA --}}
            <section class="mt-12 overflow-hidden rounded-xl bg-forest-800 px-6 py-8 text-white lg:px-10 lg:py-10">
                <div class="flex flex-col gap-6 lg:flex-row lg:items-center">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-[1.5rem] font-bold">Ready to move from reading to buying?</h2>
                        <p class="mt-2 max-w-2xl text-[1.125rem] leading-relaxed text-forest-100">
                            Post one request and verified Cameroonian exporters quote against it — species, grade, volume, incoterm and destination port.
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-3">
                        <a href="{{ route('rfq.create') }}" class="rounded-lg bg-white px-5 py-2.5 text-[1.0625rem] font-semibold text-forest-800 transition hover:bg-forest-50">Request a quote</a>
                        <a href="{{ route('directory') }}" class="rounded-lg border border-white/40 px-5 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-white/10">Browse suppliers</a>
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-layouts.app>
