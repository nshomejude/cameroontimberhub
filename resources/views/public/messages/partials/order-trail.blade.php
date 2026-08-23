{{--
    The horizontal milestone trail from the mockups.

    Entirely LIVE — it snapshots nothing. Every dot and every date comes from
    Order::milestones(), which reads the real timestamp columns and returns a
    null `at` for anything that has not happened. A pending milestone therefore
    prints "Pending" and no date; there are no projected dates anywhere in here,
    because the platform has no basis for projecting one.

    Requires: $order
--}}
@php
    $milestones = $order->milestones();
@endphp

<div class="mt-3 overflow-x-auto">
    <ol class="flex min-w-[34rem] items-start">
        @foreach ($milestones as $index => $milestone)
            @php
                $reached = $milestone['reached'];
                $current = $reached && $order->status === $milestone['status'];
                $nextReached = ($milestones[$index + 1]['reached'] ?? false);
            @endphp

            <li class="flex flex-1 flex-col items-center text-center">
                <div class="flex w-full items-center">
                    {{-- Left half of the connector; invisible on the first dot. --}}
                    <span @class([
                        'h-0.5 flex-1',
                        'bg-transparent' => $index === 0,
                        'bg-forest-600' => $index > 0 && $reached,
                        'bg-sand-300' => $index > 0 && ! $reached,
                    ])></span>

                    <span @class([
                        'flex h-7 w-7 shrink-0 items-center justify-center rounded-full',
                        'bg-forest-700 text-white' => $reached && ! $current,
                        'bg-forest-800 text-white ring-4 ring-forest-100' => $current,
                        'border border-sand-300 bg-white text-ink-soft' => ! $reached,
                    ])>
                        @if ($reached)
                            <x-heroicon-m-check class="h-4 w-4" />
                        @else
                            <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                        @endif
                    </span>

                    <span @class([
                        'h-0.5 flex-1',
                        'bg-transparent' => $index === count($milestones) - 1,
                        'bg-forest-600' => $index < count($milestones) - 1 && $nextReached,
                        'bg-sand-300' => $index < count($milestones) - 1 && ! $nextReached,
                    ])></span>
                </div>

                <p @class([
                    'mt-1.5 px-1 text-[0.875rem] font-semibold leading-tight',
                    'text-forest-800' => $reached,
                    'text-ink-soft' => ! $reached,
                ])>{{ $milestone['status']->label() }}</p>

                <p class="px-1 text-[0.8125rem] leading-tight text-ink-soft">
                    @if ($milestone['at'])
                        {{ $milestone['at']->isoFormat('D MMM YYYY') }}<br>{{ $milestone['at']->format('g:i A') }}
                    @else
                        Pending
                    @endif
                </p>
            </li>
        @endforeach
    </ol>
</div>
