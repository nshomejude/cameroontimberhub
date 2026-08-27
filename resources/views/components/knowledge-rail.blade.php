@props([
    // The hub this page belongs to, or null when the page sits outside a hub
    // (the /insights index, an unhubbed news article). Nothing is marked
    // current in that case — no hub is invented for a page that has none.
    'hub' => null,
    // Articles to nest under the current hub. Empty by default, so the rail is
    // a plain hub list when a caller has nothing to nest.
    'articles' => null,
    // The article being read, when one is. Marked aria-current="page".
    'current' => null,
])

@php
    $panel = 'rounded-xl border border-sand-300/70 bg-white px-5 py-4';
    $railArticles = $articles ?? collect();
@endphp

{{-- The Knowledge Centre navigation rail.
     Visibility is the caller's decision — an article page carries a right rail
     as well and can only afford this at xl, while a listing page has room
     sooner. The default is the article-page gate; pass a class to override.
     The hub list comes from the enum, so the rail itself costs no queries. --}}
<aside {{ $attributes->merge(['class' => 'hidden xl:block']) }}>
    <nav aria-label="Knowledge Centre" class="sticky top-[88px] max-h-[calc(100vh-7rem)] overflow-y-auto {{ $panel }}">
        <a href="{{ route('knowledge.index') }}"
           class="inline-flex items-center gap-1.5 rounded text-[1rem] font-bold text-ink transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">
            <x-heroicon-m-arrow-left class="h-3.5 w-3.5" />
            Knowledge Centre
        </a>

        <ul class="mt-3 space-y-1.5">
            @foreach (\App\Enums\KnowledgeHub::cases() as $navHub)
                @php($isCurrentHub = $hub === $navHub)
                <li>
                    <a href="{{ $navHub->url() }}"
                       @if ($isCurrentHub) aria-current="true" @endif
                       class="block rounded px-2 py-1 text-[1.0625rem] leading-snug transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300 {{ $isCurrentHub ? 'bg-forest-50 font-semibold text-forest-800' : 'text-ink-soft hover:text-forest-700' }}">
                        {{ $navHub->label() }}
                    </a>

                    @if ($isCurrentHub && $railArticles->isNotEmpty())
                        <ul class="ml-2 mt-1.5 space-y-1.5 border-l border-sand-300/70 pl-3">
                            @foreach ($railArticles as $sibling)
                                @php($isCurrentArticle = $current !== null && $sibling->getKey() === $current->getKey())
                                <li>
                                    <a href="{{ $sibling->url() }}"
                                       @if ($isCurrentArticle) aria-current="page" @endif
                                       class="block rounded text-[1.0625rem] leading-snug transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300 {{ $isCurrentArticle ? 'font-semibold text-ink' : 'text-ink-soft hover:text-forest-700' }}">
                                        {{ $sibling->title }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    </nav>
</aside>
