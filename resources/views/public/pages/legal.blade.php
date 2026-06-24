<x-layouts.app
    :title="$page->title"
    :description="$page->meta_description"
    :canonical="$page->canonical_url">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-3xl px-4 py-14">
            <p class="eyebrow">Legal</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl">
                {{ $page->h1 ?: $page->title }}
            </h1>
            <p class="mt-3 text-sm text-ink-soft dark:text-[#b3ab9b]">
                Last updated {{ $page->updated_at->format('j F Y') }}
            </p>
        </div>
    </section>

    <div class="mx-auto max-w-3xl px-4 py-12">
        @if(is_array($page->data) && !empty($page->data['blocks']))
            <div class="prose prose-neutral dark:prose-invert max-w-none">
                @foreach($page->data['blocks'] as $block)
                    @if(($block['type'] ?? '') === 'heading')
                        <h2>{{ $block['content'] ?? '' }}</h2>
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
        @endif
    </div>
</x-layouts.app>
