<x-layouts.app
    :title="$page->title"
    :description="$page->meta_description"
    :canonical="$page->canonical_url">

    {{-- Hero --}}
    <section class="relative overflow-hidden border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-6xl px-4 py-16 text-center">
            <p class="eyebrow">Verified B2B timber platform</p>
            <h1 class="mt-4 font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl lg:text-6xl">
                {{ $page->h1 ?: $page->title }}
            </h1>
            @if($page->meta_description)
                <p class="mx-auto mt-5 max-w-2xl text-lg text-ink-soft dark:text-[#b3ab9b]">{{ $page->meta_description }}</p>
            @endif
            <div class="mt-8 flex flex-wrap justify-center gap-4">
                <a href="{{ route('rfq.create') }}" class="inline-flex items-center gap-1.5 rounded-full bg-timber-400 px-6 py-3 text-sm font-semibold text-forest-950 transition hover:bg-timber-300">
                    Request a quote <x-heroicon-m-arrow-right class="h-4 w-4" />
                </a>
                <a href="{{ route('directory') }}" class="inline-flex items-center gap-1.5 rounded-full border border-forest-300 dark:border-forest-700 bg-white dark:bg-[#1f1d18] px-6 py-3 text-sm font-semibold text-forest-700 dark:text-forest-300 transition hover:bg-forest-50 dark:hover:bg-forest-950">
                    Browse directory
                </a>
            </div>
        </div>
    </section>

    {{-- CMS content blocks --}}
    @if(is_array($page->data) && !empty($page->data['blocks']))
        <div class="mx-auto max-w-3xl px-4 py-12">
            @foreach($page->data['blocks'] as $block)
                @if(($block['type'] ?? '') === 'heading')
                    <h2 class="mt-10 font-display text-2xl font-semibold text-forest-950 dark:text-sand-100 first:mt-0">{{ $block['content'] ?? '' }}</h2>
                @elseif(($block['type'] ?? '') === 'list')
                    <ul class="mt-4 space-y-2">
                        @foreach($block['items'] ?? [] as $item)
                            <li class="flex items-start gap-2 text-ink-soft dark:text-[#b3ab9b]">
                                <x-heroicon-m-check-circle class="mt-0.5 h-5 w-5 shrink-0 text-forest-500" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-4 leading-relaxed text-ink-soft dark:text-[#b3ab9b]">{{ $block['content'] ?? '' }}</p>
                @endif
            @endforeach
        </div>
    @endif

    {{-- Company grid --}}
    <section class="mx-auto max-w-6xl px-4 py-12">
        <div class="flex items-center justify-between">
            <h2 class="font-display text-2xl font-semibold text-forest-950 dark:text-sand-100">
                Verified timber exporters from Cameroon
            </h2>
            <a href="{{ route('directory') }}" class="text-sm font-medium text-forest-700 dark:text-forest-300 hover:underline">
                View all <x-heroicon-m-arrow-right class="inline h-4 w-4" />
            </a>
        </div>

        @if($companies->isNotEmpty())
            <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($companies as $company)
                    @include('public.partials.company-card', ['company' => $company])
                @endforeach
            </div>
            <div class="mt-8">{{ $companies->links() }}</div>
        @else
            <div class="mt-6 rounded-2xl border border-dashed border-sand-300 dark:border-[#3a352e] bg-white dark:bg-[#1f1d18] p-12 text-center">
                <p class="text-ink-soft dark:text-[#b3ab9b]">No verified exporters found. Check back soon.</p>
            </div>
        @endif

        <p class="mt-8 text-center text-xs text-ink-soft dark:text-[#b3ab9b]">
            Documents are reviewed by Cameroon Timber Hub based on information submitted by companies. Buyers should conduct final due diligence before any transaction.
        </p>
    </section>
</x-layouts.app>
