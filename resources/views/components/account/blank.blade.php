@props([
    'icon' => 'inbox',
    'title',
    'body' => null,
    'ctaLabel' => null,
    'ctaUrl' => null,
])

{{-- Empty state for a list with nothing in it yet. Always offers the next
     useful step rather than a dead end. --}}
<div {{ $attributes->class('flex flex-col items-center rounded-2xl border border-dashed border-sand-300 bg-white px-6 py-12 text-center') }}>
    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-forest-50 text-forest-700">
        <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-7 w-7" />
    </span>
    <h3 class="mt-4 font-display text-lg font-bold text-forest-950">{{ $title }}</h3>
    @if ($body)
        <p class="mt-2 max-w-md text-[1.0625rem] leading-relaxed text-ink-soft">{{ $body }}</p>
    @endif
    @if ($ctaUrl && $ctaLabel)
        <a href="{{ $ctaUrl }}"
           class="mt-5 inline-flex items-center gap-2 rounded-full bg-forest-700 px-5 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800">
            {{ $ctaLabel }} <x-heroicon-m-arrow-right class="h-4 w-4" />
        </a>
    @endif
</div>
