<x-layouts.app
    :title="$page->title"
    :description="$page->meta_description"
    :canonical="$page->canonical_url">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-3xl px-4 py-14">
            <h1 class="font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl">
                {{ $page->h1 ?: $page->title }}
            </h1>
            @if($page->meta_description)
                <p class="mt-4 text-lg text-ink-soft dark:text-[#b3ab9b]">{{ $page->meta_description }}</p>
            @endif
        </div>
    </section>

    <div class="mx-auto max-w-3xl px-4 py-12">
        @if(is_array($page->data) && !empty($page->data['blocks']))
            @foreach($page->data['blocks'] as $block)
                @if(($block['type'] ?? '') === 'heading')
                    <h2 class="mt-10 font-display text-2xl font-semibold text-forest-950 dark:text-sand-100 first:mt-0">{{ $block['content'] ?? '' }}</h2>
                @elseif(($block['type'] ?? '') === 'subheading')
                    <h3 class="mt-8 font-display text-xl font-semibold text-forest-900 dark:text-sand-100">{{ $block['content'] ?? '' }}</h3>
                @elseif(($block['type'] ?? '') === 'list')
                    <ul class="mt-4 space-y-2 text-ink-soft dark:text-[#b3ab9b]">
                        @foreach($block['items'] ?? [] as $item)
                            <li class="flex items-start gap-2">
                                <x-heroicon-m-check-circle class="mt-0.5 h-5 w-5 shrink-0 text-forest-500" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-4 leading-relaxed text-ink-soft dark:text-[#b3ab9b]">{{ $block['content'] ?? '' }}</p>
                @endif
            @endforeach
        @endif

        <div class="mt-12 flex flex-wrap gap-4 border-t border-sand-200 dark:border-[#2c2a24] pt-10">
            <a href="{{ route('directory') }}" class="inline-flex items-center gap-1.5 rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">
                Browse verified exporters <x-heroicon-m-arrow-right class="h-4 w-4" />
            </a>
            <a href="{{ route('rfq.create') }}" class="inline-flex items-center gap-1.5 rounded-full border border-forest-300 dark:border-forest-700 px-5 py-2.5 text-sm font-semibold text-forest-700 dark:text-forest-300 transition hover:bg-forest-50 dark:hover:bg-forest-950">
                Request a quote
            </a>
        </div>
    </div>
</x-layouts.app>
