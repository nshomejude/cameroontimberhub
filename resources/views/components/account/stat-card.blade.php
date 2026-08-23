@props(['stat'])

{{--
    One headline tile. `value` is always a real count from BuyerDashboard, and
    `delta` is only ever present when the comparison month actually had rows —
    otherwise the line is omitted rather than showing a percentage against zero.
--}}
<a href="{{ $stat['url'] ?? '#' }}"
   class="flex flex-col rounded-2xl border border-sand-200 bg-white p-4 transition hover:border-forest-300 hover:shadow-sm lg:p-5">
    <div class="flex items-start gap-3">
        <span class="order-2 ml-auto flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-forest-50 text-forest-700 lg:order-none lg:ml-0">
            <x-dynamic-component :component="'heroicon-o-'.$stat['icon']" class="h-5 w-5" />
        </span>
        <div class="min-w-0">
            <p class="text-[0.9375rem] font-medium text-ink-soft lg:text-[1.0625rem]">{{ $stat['label'] }}</p>
            <p class="mt-1 font-display text-[1.75rem] font-bold leading-none text-forest-950">{{ number_format($stat['value']) }}</p>
        </div>
    </div>

    @if ($stat['delta'])
        <p class="mt-3 flex items-center gap-1 text-[0.9375rem]">
            @if ($stat['delta']['direction'] === 'up')
                <x-heroicon-m-arrow-trending-up class="h-4 w-4 text-forest-500" />
                <span class="font-bold text-forest-600">{{ $stat['delta']['percent'] }}%</span>
            @else
                <x-heroicon-m-arrow-trending-down class="h-4 w-4 text-timber-600" />
                <span class="font-bold text-timber-700">{{ $stat['delta']['percent'] }}%</span>
            @endif
            <span class="text-ink-soft">{{ $stat['delta']['period'] }}</span>
        </p>
    @elseif (! empty($stat['hint']))
        <p class="mt-3 text-[0.9375rem] leading-snug text-ink-soft">{{ $stat['hint'] }}</p>
    @endif
</a>
