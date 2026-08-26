@props([
    'paginator',
    'noun' => 'results',
])

@php
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();

    // Windowed page list: first, a band around the current page, last — with
    // ellipses where pages are skipped (matches the mockup's "1 2 3 4 5 … 22").
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

    $btn = 'flex h-9 min-w-9 items-center justify-center rounded-lg border px-3 text-[1.0625rem] font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-1';
@endphp

@if ($paginator->hasPages() || $paginator->total() > 0)
    <div class="flex flex-col items-center gap-3 sm:flex-row">
        @if ($paginator->hasPages())
            <nav class="flex flex-wrap items-center justify-center gap-2 sm:mx-auto" aria-label="Pagination">
                <button type="button" wire:click="previousPage" @disabled($paginator->onFirstPage())
                        class="{{ $btn }} border-sand-300 text-ink-soft hover:border-forest-600 hover:text-forest-700 disabled:cursor-not-allowed disabled:opacity-40"
                        aria-label="Previous page">
                    <x-heroicon-m-chevron-left class="h-4 w-4" />
                </button>

                @foreach ($items as $item)
                    @if ($item === null)
                        <span class="px-1 text-[1.0625rem] text-ink-soft" aria-hidden="true">…</span>
                    @else
                        <button type="button" wire:click="gotoPage({{ $item }})"
                                @if ($item === $current) aria-current="page" @endif
                                @class([
                                    $btn,
                                    'border-forest-700 bg-forest-700 text-white' => $item === $current,
                                    'border-sand-300 text-ink hover:border-forest-600 hover:text-forest-700' => $item !== $current,
                                ])>
                            {{ $item }}
                        </button>
                    @endif
                @endforeach

                <button type="button" wire:click="nextPage" @disabled(! $paginator->hasMorePages())
                        class="{{ $btn }} border-sand-300 text-ink-soft hover:border-forest-600 hover:text-forest-700 disabled:cursor-not-allowed disabled:opacity-40"
                        aria-label="Next page">
                    <x-heroicon-m-chevron-right class="h-4 w-4" />
                </button>
            </nav>
        @endif

        <p class="text-[1.0625rem] text-ink-soft sm:ml-auto">
            Showing {{ $paginator->firstItem() ?? 0 }} to {{ $paginator->lastItem() ?? 0 }} of {{ $paginator->total() }} {{ $noun }}
        </p>
    </div>
@endif
