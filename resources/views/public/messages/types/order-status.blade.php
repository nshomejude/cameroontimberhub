{{--
    ORDER STATUS trail.

    This card snapshots nothing at all: every milestone and every timestamp is
    read from the live Order via Order::milestones(), which returns a null `at`
    for anything that has not actually happened. Nothing is projected.
--}}
@php $order = $message->related; @endphp

@if ($order)
    <div class="py-1">
        <div class="mb-2 flex items-center gap-3">
            <span class="h-px flex-1 bg-sand-300"></span>
            <span class="text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-ink-soft">Order status</span>
            <span class="h-px flex-1 bg-sand-300"></span>
        </div>

        <div class="rounded-2xl border border-sand-200 bg-white p-4 shadow-sm">
            <ol class="space-y-3">
                @foreach ($order->milestones() as $milestone)
                    @php $current = $milestone['reached'] && $order->status === $milestone['status']; @endphp
                    <li class="flex items-start gap-3">
                        <span @class([
                            'mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full',
                            'bg-forest-700 text-white' => $milestone['reached'],
                            'bg-sand-200 text-ink-soft' => ! $milestone['reached'],
                        ])>
                            @if ($milestone['reached'])
                                <x-heroicon-m-check class="h-3.5 w-3.5" />
                            @else
                                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                            @endif
                        </span>
                        <div class="min-w-0">
                            <p class="text-[0.875rem] font-semibold text-ink">
                                {{ $milestone['status']->label() }}
                                @if ($current)
                                    <span class="ml-1 rounded-full bg-forest-100 px-2 py-0.5 text-[0.625rem] font-bold uppercase tracking-wide text-forest-800">Current</span>
                                @endif
                            </p>
                            <p class="text-[0.75rem] text-ink-soft">
                                {{ $milestone['at']?->isoFormat('D MMM YYYY, h:mm A') ?? 'Pending' }}
                            </p>
                        </div>
                    </li>
                @endforeach
            </ol>

            <p class="mt-2 text-right text-[0.6875rem] text-ink-soft">{{ $message->created_at->format('g:i A') }}</p>
        </div>
    </div>
@endif
