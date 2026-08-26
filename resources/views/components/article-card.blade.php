@props(['article', 'featured' => false])

@php
    $url = $article->url();
    $hero = $article->heroImageUrl();
@endphp

<article @class([
    'group relative flex flex-col overflow-hidden rounded-xl border border-sand-300/70 bg-white transition hover:border-forest-200 hover:shadow-lg',
    'sm:flex-row' => $featured,
])>

    <div @class([
        'relative overflow-hidden bg-sand-100',
        'sm:w-[22rem] sm:shrink-0' => $featured,
    ])>
        @if ($hero)
            <img src="{{ $hero }}" alt="" loading="lazy" width="800" height="450"
                 class="{{ $featured ? 'h-full min-h-[13rem] w-full object-cover' : 'aspect-[16/9] w-full object-cover' }}">
        @else
            {{-- No artwork on file: a tinted, generated stand-in rather than a
                 stock photograph that would misrepresent the subject. --}}
            <div class="{{ $featured ? 'h-full min-h-[13rem] w-full' : 'aspect-[16/9] w-full' }}"
                 aria-hidden="true"
                 style="background-image:
                        repeating-linear-gradient(103deg, rgba(0,0,0,.08) 0 2px, rgba(255,255,255,.05) 2px 8px, rgba(0,0,0,0) 8px 17px),
                        linear-gradient(155deg, #0a5223 0%, #15703d 55%, #834b27 100%);"></div>
        @endif

        <span class="absolute left-2.5 top-2.5 rounded-md bg-white/95 px-2 py-1 text-[0.875rem] font-semibold text-forest-800">
            {{ $article->category->shortLabel() }}
        </span>
    </div>

    <div class="flex flex-1 flex-col px-4 pb-4 pt-4">
        <h3 class="{{ $featured ? 'text-[1.375rem]' : 'text-[1.1875rem]' }} font-bold leading-snug text-ink">
            <a href="{{ $url }}" class="rounded transition after:absolute after:inset-0 hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500">
                {{ $article->title }}
            </a>
        </h3>

        @if ($article->excerpt)
            <p class="mt-2 line-clamp-3 text-[1.0625rem] leading-relaxed text-ink-soft">
                {{ Str::limit(strip_tags($article->excerpt), $featured ? 220 : 140) }}
            </p>
        @endif

        <div class="mt-auto flex items-center gap-2 border-t border-sand-200 pt-3 {{ $featured ? 'mt-5' : 'mt-4' }}">
            <p class="text-[0.9375rem] text-ink-soft">
                <time datetime="{{ $article->published_at?->toDateString() }}">{{ $article->published_at?->format('d M Y') }}</time>
                <span aria-hidden="true">·</span>
                {{ $article->readingTime() }} min read
            </p>
            <span class="ml-auto inline-flex items-center gap-1 text-[0.9375rem] font-semibold text-forest-700">
                Read <x-heroicon-m-arrow-right class="h-3.5 w-3.5 transition group-hover:translate-x-0.5" />
            </span>
        </div>
    </div>
</article>
