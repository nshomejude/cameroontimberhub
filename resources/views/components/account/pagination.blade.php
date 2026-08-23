@props([
    'paginator',
    'noun' => 'results',
])

@php
    /**
     * Server-rendered pagination for the account lists.
     *
     * Deliberately NOT `$paginator->links()`: Livewire replaces Laravel's
     * default paginator view globally once it boots, which turns these links
     * into `wire:click` buttons that do nothing outside a Livewire component.
     * These pages are plain Blade, so the links have to be real hrefs.
     */
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();

    $pages = collect(range(1, $last))
        ->filter(fn (int $p): bool => $p === 1 || $p === $last || abs($p - $current) <= 2)
        ->values();

    $items = [];
    $previous = 0;
    foreach ($pages as $p) {
        if ($p - $previous > 1) {
            $items[] = null; // ellipsis
        }
        $items[] = $p;
        $previous = $p;
    }

    $btn = 'flex h-9 min-w-9 items-center justify-center rounded-lg border px-3 text-[1.0625rem] font-semibold transition';
@endphp

@if ($paginator->total() > 0)
    <div class="flex flex-col items-center gap-3 sm:flex-row">
        @if ($paginator->hasPages())
            <nav class="flex flex-wrap items-center justify-center gap-2" aria-label="Pagination">
                @if ($paginator->onFirstPage())
                    <span class="{{ $btn }} border-sand-300 text-ink-soft opacity-40" aria-hidden="true">
                        <x-heroicon-m-chevron-left class="h-4 w-4" />
                    </span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page"
                       class="{{ $btn }} border-sand-300 text-ink-soft hover:border-forest-600 hover:text-forest-700">
                        <x-heroicon-m-chevron-left class="h-4 w-4" />
                    </a>
                @endif

                @foreach ($items as $item)
                    @if ($item === null)
                        <span class="px-1 text-[1.0625rem] text-ink-soft" aria-hidden="true">…</span>
                    @elseif ($item === $current)
                        <span aria-current="page" class="{{ $btn }} border-forest-700 bg-forest-700 text-white">{{ $item }}</span>
                    @else
                        <a href="{{ $paginator->url($item) }}"
                           class="{{ $btn }} border-sand-300 text-ink hover:border-forest-600 hover:text-forest-700">{{ $item }}</a>
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page"
                       class="{{ $btn }} border-sand-300 text-ink-soft hover:border-forest-600 hover:text-forest-700">
                        <x-heroicon-m-chevron-right class="h-4 w-4" />
                    </a>
                @else
                    <span class="{{ $btn }} border-sand-300 text-ink-soft opacity-40" aria-hidden="true">
                        <x-heroicon-m-chevron-right class="h-4 w-4" />
                    </span>
                @endif
            </nav>
        @endif

        <p class="text-[1.0625rem] text-ink-soft sm:ml-auto">
            Showing {{ $paginator->firstItem() ?? 0 }} to {{ $paginator->lastItem() ?? 0 }} of {{ number_format($paginator->total()) }} {{ $noun }}
        </p>
    </div>
@endif
