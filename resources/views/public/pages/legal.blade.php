<x-layouts.app
    :title="$page->title"
    :description="$page->meta_description"
    :canonical="$page->canonical_url">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-5xl px-4 py-14">
            <p class="eyebrow">Legal</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl">
                {{ $page->h1 ?: $page->title }}
            </h1>
            @if($page->meta_description)
                <p class="mt-4 max-w-2xl text-lg text-ink-soft dark:text-[#b3ab9b]">{{ $page->meta_description }}</p>
            @endif
            <p class="mt-4 text-sm text-ink-soft dark:text-[#b3ab9b]">
                Last updated {{ $page->updated_at->format('j F Y') }}
            </p>
        </div>
    </section>

    @php
        // Build a table of contents from every 'heading' block, and give each
        // one a stable anchor id so the TOC (and any external link) can jump
        // straight to a section. Slug collisions (two headings with the same
        // text) are disambiguated with a numeric suffix.
        $blocks = is_array($page->data) ? ($page->data['blocks'] ?? []) : [];
        $seenSlugs = [];
        $blocks = collect($blocks)->map(function (array $block) use (&$seenSlugs) {
            if (($block['type'] ?? null) === 'heading') {
                $base = \Illuminate\Support\Str::slug($block['content'] ?? '');
                $slug = $base;
                $i = 2;
                while (in_array($slug, $seenSlugs, true)) {
                    $slug = $base.'-'.$i++;
                }
                $seenSlugs[] = $slug;
                $block['id'] = $slug;
            }

            return $block;
        })->all();
        $headings = collect($blocks)->where('type', 'heading');
    @endphp

    <div class="mx-auto max-w-5xl px-4 py-12">
        <div class="lg:grid lg:grid-cols-[220px_1fr] lg:gap-12">
            @if($headings->isNotEmpty())
                <nav aria-label="Table of contents" class="mb-10 lg:mb-0">
                    <div class="lg:sticky lg:top-24">
                        <p class="text-xs font-semibold tracking-wide text-ink-soft dark:text-[#8a8171] uppercase">On this page</p>
                        <ul class="mt-3 space-y-2 border-l border-sand-200 dark:border-[#2c2a24] pl-4 text-sm">
                            @foreach($headings as $heading)
                                <li>
                                    <a href="#{{ $heading['id'] }}" class="text-ink-soft transition hover:text-forest-700 dark:text-[#b3ab9b] dark:hover:text-forest-300">
                                        {{ $heading['content'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </nav>
            @endif

            <div class="min-w-0 article-prose">
                @foreach($blocks as $block)
                    @if(($block['type'] ?? '') === 'heading')
                        <h2 id="{{ $block['id'] }}">{{ $block['content'] ?? '' }}</h2>
                    @elseif(($block['type'] ?? '') === 'subheading')
                        <h3>{{ $block['content'] ?? '' }}</h3>
                    @elseif(($block['type'] ?? '') === 'list')
                        <ul>
                            @foreach($block['items'] ?? [] as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    @else
                        <p>{{ $block['content'] ?? '' }}</p>
                    @endif
                @endforeach
            </div>
        </div>

        <div class="mt-12 flex flex-wrap gap-4 border-t border-sand-200 dark:border-[#2c2a24] pt-10">
            <a href="{{ route('contact') }}" class="inline-flex items-center gap-1.5 rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">
                Questions? Contact us <x-heroicon-m-arrow-right class="h-4 w-4" />
            </a>
        </div>
    </div>
</x-layouts.app>
