@props([
    'rating',
    'count' => null,
    'size' => 'h-4 w-4',
])

@php
    // Rendered only where a real rating exists; callers guard on hasRating().
    $value = round((float) $rating * 2) / 2;
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5']) }}>
    <span class="flex items-center gap-0.5" role="img"
          aria-label="Rated {{ rtrim(rtrim(number_format((float) $rating, 1), '0'), '.') }} out of 5">
        @for ($i = 1; $i <= 5; $i++)
            @if ($value >= $i)
                <x-heroicon-s-star class="{{ $size }} text-timber-400" />
            @elseif ($value >= $i - 0.5)
                <span class="relative {{ $size }}">
                    <x-heroicon-o-star class="absolute inset-0 {{ $size }} text-timber-400" />
                    <span class="absolute inset-y-0 left-0 w-1/2 overflow-hidden">
                        <x-heroicon-s-star class="{{ $size }} text-timber-400" />
                    </span>
                </span>
            @else
                <x-heroicon-o-star class="{{ $size }} text-sand-400" />
            @endif
        @endfor
    </span>
    <span class="text-[1.0625rem] font-bold text-ink">{{ rtrim(rtrim(number_format((float) $rating, 1), '0'), '.') }}</span>
    @if ($count)
        <span class="text-[1.0625rem] text-ink-soft">({{ number_format($count) }} {{ Str::plural('review', $count) }})</span>
    @endif
</span>
