<x-layouts.account
    title="Trade Assurance"
    heading="Trade Assurance"
    subheading="Milestones agreed for order {{ $order->reference_code }}. Trade Assurance is a coordination tool — it tracks progress, it does not hold or move money.">

    @if (session('status'))
        <div class="mb-4 rounded-xl border border-forest-200 bg-forest-50 px-4 py-3 text-[0.95rem] text-forest-700">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-[0.95rem] text-red-700">
            {{ session('error') }}
        </div>
    @endif

    @if (! $agreement)
        <x-account.blank icon="shield-check"
            title="No Trade Assurance agreement yet"
            body="A Trade Assurance agreement has not been set up for this order."
            cta-label="Back to orders" :cta-url="route('account.orders')" />
    @else
        <div class="overflow-hidden rounded-2xl border border-sand-200 bg-white">
            <ul class="divide-y divide-sand-200">
                @foreach ($agreement->milestones as $milestone)
                    <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="font-semibold text-ink">{{ $milestone->sequence }}. {{ $milestone->title }}</p>
                            @if ($milestone->description)
                                <p class="mt-1 text-[0.9375rem] text-ink-soft">{{ $milestone->description }}</p>
                            @endif
                            <p class="mt-1 text-[0.875rem] text-ink-soft">
                                Status: <span class="font-medium text-ink">{{ $milestone->status->label() }}</span>
                                @if ($milestone->expected_completion_date)
                                    &middot; Expected {{ $milestone->expected_completion_date->isoFormat('D MMM YYYY') }}
                                @endif
                                @if ($milestone->confirmed_at)
                                    &middot; Confirmed {{ $milestone->confirmed_at->isoFormat('D MMM YYYY, h:mm A') }}
                                @endif
                            </p>
                        </div>

                        @if (in_array($milestone->status->value, ['pending', 'in_progress'], true))
                            <form method="POST" action="{{ route('account.orders.trade-assurance.confirm', ['order' => $order, 'milestone' => $milestone]) }}">
                                @csrf
                                <button type="submit"
                                    class="inline-flex items-center justify-center rounded-full bg-forest-700 px-4 py-2 text-[0.9375rem] font-semibold text-white transition hover:bg-forest-800">
                                    Confirm milestone
                                </button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</x-layouts.account>
