@php
    $hero = $article->heroImageUrl();
    $updated = $article->updated_at ?? $article->published_at;
    $panel = 'rounded-xl border border-sand-300/70 bg-white px-5 py-4';
    $sectionTitle = 'text-[1.25rem] font-bold tracking-tight text-ink';
@endphp

<x-layouts.app
    :title="$article->meta_title ?: $article->title"
    :description="$article->meta_description ?: Str::limit(strip_tags((string) $article->excerpt), 160)"
    :image="$hero"
    :breadcrumbs="$breadcrumbs"
    :schema="$schema">

    <div class="bg-white">
        <div class="mx-auto max-w-[1400px] px-4 py-6 lg:px-6">

            {{-- Breadcrumb --}}
            <nav aria-label="Breadcrumb">
                <ol class="flex flex-wrap items-center gap-2 text-[0.9375rem] text-ink-soft">
                    <li><a href="{{ route('home') }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">Home</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('insights.index') }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">Insights</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('insights.category', $article->category->value) }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">{{ $article->category->label() }}</a></li>
                    <li aria-hidden="true">/</li>
                    <li><span aria-current="page" class="font-medium text-ink">{{ Str::limit($article->title, 60) }}</span></li>
                </ol>
            </nav>

            {{-- ---------------- Masthead ---------------- --}}
            <header class="mt-4 max-w-[46rem]">
                <a href="{{ route('insights.category', $article->category->value) }}"
                   class="inline-flex rounded-md bg-forest-50 px-2.5 py-1 text-[0.9375rem] font-semibold text-forest-800 transition hover:bg-forest-100">
                    {{ $article->category->label() }}
                </a>

                <h1 class="mt-3 text-[2rem] font-bold leading-tight tracking-tight text-ink lg:text-[2.5rem]">{{ $article->heading }}</h1>

                @if ($article->excerpt)
                    <p class="mt-3 text-[1.125rem] leading-relaxed text-ink-soft">{{ $article->excerpt }}</p>
                @endif

                <div class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[1rem] text-ink-soft">
                    <span class="font-medium text-ink">{{ $article->byline }}</span>
                    @if ($article->author_role)
                        <span aria-hidden="true">·</span><span>{{ $article->author_role }}</span>
                    @endif
                    <span aria-hidden="true">·</span>
                    <span>{{ $article->readingTime() }} min read</span>
                    <span aria-hidden="true">·</span>
                    <span>Updated <time datetime="{{ $updated?->toDateString() }}">{{ $updated?->format('d M Y') }}</time></span>
                </div>
            </header>

            @if ($hero)
                <img src="{{ $hero }}" alt="{{ $article->title }}" width="1400" height="620"
                     class="mt-6 aspect-[21/9] w-full rounded-xl object-cover">
            @endif

            {{-- ---------------- Body + rail ---------------- --}}
            <div class="mt-8 grid gap-10 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">

                <div class="min-w-0">

                    {{-- Table of contents, built from the article's own H2s. --}}
                    @if (count($toc) > 1)
                        <nav aria-labelledby="toc-heading" class="mb-8 rounded-xl border border-sand-300/70 bg-sand-50 px-5 py-4 lg:hidden">
                            <h2 id="toc-heading" class="text-[1rem] font-bold text-ink">On this page</h2>
                            <ol class="mt-2 space-y-1.5">
                                @foreach ($toc as $i => $item)
                                    <li class="flex gap-2 text-[1.0625rem]">
                                        <span class="tabular-nums text-ink-soft">{{ $i + 1 }}.</span>
                                        <a href="#{{ $item['id'] }}" class="rounded text-forest-700 underline-offset-2 transition hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">{{ $item['text'] }}</a>
                                    </li>
                                @endforeach
                            </ol>
                        </nav>
                    @endif

                    <div class="article-prose">
                        {!! $article->renderedBody() !!}
                    </div>

                    {{-- Mid-funnel CTA: after the body, before the FAQ, so it
                         never interrupts the read. --}}
                    <aside class="mt-10 rounded-xl border border-forest-200 bg-forest-50 px-5 py-5">
                        <h2 class="text-[1.1875rem] font-bold text-ink">Sourcing the timber in this article?</h2>
                        <p class="mt-1.5 text-[1.0625rem] leading-relaxed text-ink-soft">
                            Send one specification to verified Cameroonian exporters and compare their quotes side by side.
                        </p>
                        <div class="mt-4 flex flex-wrap gap-3">
                            <a href="{{ route('rfq.create') }}" class="rounded-lg bg-forest-700 px-4 py-2 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800">Post an RFQ</a>
                            <a href="{{ route('directory') }}" class="rounded-lg border border-forest-700/30 bg-white px-4 py-2 text-[1.0625rem] font-semibold text-forest-800 transition hover:border-forest-700">View suppliers</a>
                            <a href="{{ url('/marketplace') }}" class="rounded-lg border border-forest-700/30 bg-white px-4 py-2 text-[1.0625rem] font-semibold text-forest-800 transition hover:border-forest-700">Browse marketplace</a>
                        </div>
                    </aside>

                    {{-- FAQ. Rendered from exactly the same pairs the FAQPage
                         JSON-LD is built from — the markup can never claim an
                         answer the page does not show. --}}
                    @if ($faqs)
                        <section class="mt-10" aria-labelledby="faq-heading">
                            <h2 id="faq-heading" class="{{ $sectionTitle }}">Frequently asked questions</h2>
                            <div class="mt-4 divide-y divide-sand-200 rounded-xl border border-sand-300/70 bg-white">
                                @foreach ($faqs as $faq)
                                    <details class="group px-5 py-4" @if ($loop->first) open @endif>
                                        <summary class="flex cursor-pointer list-none items-start gap-3 text-[1.0625rem] font-semibold text-ink marker:hidden focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">
                                            <span class="flex-1">{{ $faq['question'] }}</span>
                                            <x-heroicon-m-chevron-down class="mt-0.5 h-5 w-5 shrink-0 text-ink-soft transition group-open:rotate-180" />
                                        </summary>
                                        <div class="mt-2 text-[1.0625rem] leading-relaxed text-ink-soft">{{ $faq['answer'] }}</div>
                                    </details>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    {{-- Citations --}}
                    @if ($sources)
                        <section class="mt-10" aria-labelledby="sources-heading">
                            <h2 id="sources-heading" class="{{ $sectionTitle }}">Sources &amp; further reading</h2>
                            <ol class="mt-3 space-y-2">
                                @foreach ($sources as $i => $source)
                                    <li class="flex gap-2 text-[1.0625rem] leading-relaxed">
                                        <span class="tabular-nums text-ink-soft">{{ $i + 1 }}.</span>
                                        <a href="{{ $source['url'] }}" rel="nofollow noopener external" target="_blank"
                                           class="rounded text-forest-700 underline underline-offset-2 transition hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">
                                            {{ $source['label'] }}
                                        </a>
                                    </li>
                                @endforeach
                            </ol>
                        </section>
                    @endif
                </div>

                {{-- ---------------- Sticky rail ---------------- --}}
                <aside class="hidden lg:block">
                    <div class="sticky top-[88px] space-y-4">
                        @if (count($toc) > 1)
                            <nav aria-labelledby="toc-heading-desktop" class="{{ $panel }}">
                                <h2 id="toc-heading-desktop" class="text-[1rem] font-bold text-ink">On this page</h2>
                                <ol class="mt-2.5 space-y-2">
                                    @foreach ($toc as $i => $item)
                                        <li class="flex gap-2 text-[1.0625rem] leading-snug">
                                            <span class="tabular-nums text-ink-soft">{{ $i + 1 }}.</span>
                                            <a href="#{{ $item['id'] }}" class="rounded text-ink transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">{{ $item['text'] }}</a>
                                        </li>
                                    @endforeach
                                </ol>
                            </nav>
                        @endif

                        @if ($relatedSpecies->isNotEmpty())
                            <div class="{{ $panel }}">
                                <h2 class="text-[1rem] font-bold text-ink">Species covered here</h2>
                                <ul class="mt-2.5 space-y-1.5">
                                    @foreach ($relatedSpecies as $species)
                                        <li>
                                            <a href="{{ route('species.show', $species->slug) }}"
                                               class="inline-flex items-center gap-1.5 rounded text-[1.0625rem] font-semibold text-forest-700 transition hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">
                                                {{ $species->common_name }}
                                                <x-heroicon-m-arrow-right class="h-3.5 w-3.5" />
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="relative overflow-hidden rounded-xl bg-forest-800 px-5 py-5 text-white">
                            <x-heroicon-o-document-text class="pointer-events-none absolute -right-4 top-1/2 h-24 w-24 -translate-y-1/2 text-white/10" aria-hidden="true" />
                            <p class="text-[1.0625rem] font-bold">Get quotes on this</p>
                            <p class="mt-1 text-[1.0625rem] leading-relaxed text-forest-100">Verified exporters respond with prices, grades and lead times.</p>
                            <a href="{{ route('rfq.create') }}"
                               class="mt-3 inline-flex rounded-lg bg-white px-4 py-2 text-[1.0625rem] font-semibold text-forest-800 transition hover:bg-forest-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-forest-800">
                                Request a quote
                            </a>
                        </div>
                    </div>
                </aside>
            </div>

            {{-- ---------------- Related articles ---------------- --}}
            @if ($related->isNotEmpty())
                <section class="mt-14 border-t border-sand-200 pt-8" aria-labelledby="related-heading">
                    <h2 id="related-heading" class="{{ $sectionTitle }}">Related reading</h2>
                    <div class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($related as $item)
                            <x-article-card :article="$item" />
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    </div>
</x-layouts.app>
