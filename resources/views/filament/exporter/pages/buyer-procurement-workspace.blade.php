<x-filament-panels::page>
    @php
        $rfqs = $this->getOpenRfqs();
        $quotes = $this->getPendingQuotes();
        $orders = $this->getActiveOrders();
        $milestones = $this->getMilestonesAwaitingConfirmation();
    @endphp

    <div class="space-y-6">
        {{-- Open RFQs --}}
        <x-filament::section>
            <x-slot name="heading">Your open requests for quotation</x-slot>

            @if($rfqs->isEmpty())
                <p class="text-sm text-ink-soft">You have no open RFQs right now.</p>
            @else
                <div class="divide-y divide-sand-200 -my-6">
                    @foreach($rfqs as $rfq)
                        <div class="flex items-center justify-between gap-4 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-ink">{{ $rfq->reference_code ?? ('RFQ #'.$rfq->id) }}</p>
                                <p class="mt-0.5 text-xs text-ink-soft">{{ $rfq->shipping_port ? 'Ships via '.$rfq->shipping_port : ($rfq->deadline ? 'Deadline '.$rfq->deadline->isoFormat('D MMM YYYY') : 'Timber request') }}</p>
                            </div>
                            <x-filament::badge :color="$rfq->status->color()">
                                {{ $rfq->status->label() }}
                            </x-filament::badge>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- Pending quotes --}}
        <x-filament::section>
            <x-slot name="heading">Quotes awaiting your decision</x-slot>

            @if($quotes->isEmpty())
                <p class="text-sm text-ink-soft">No quotes are waiting on you right now.</p>
            @else
                <div class="divide-y divide-sand-200 -my-6">
                    @foreach($quotes as $quote)
                        <div class="flex items-center justify-between gap-4 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-ink">{{ $quote->reference_code ?? ('Quote #'.$quote->id) }}</p>
                                <p class="mt-0.5 text-xs text-ink-soft">
                                    From {{ $quote->company?->name ?? 'Supplier' }} &middot; {{ $quote->money($quote->total_amount) }}
                                </p>
                            </div>
                            <x-filament::badge :color="$quote->status->color()">
                                {{ $quote->status->label() }}
                            </x-filament::badge>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- Active orders --}}
        <x-filament::section>
            <x-slot name="heading">Active orders</x-slot>

            @if($orders->isEmpty())
                <p class="text-sm text-ink-soft">You have no active orders right now.</p>
            @else
                <div class="divide-y divide-sand-200 -my-6">
                    @foreach($orders as $order)
                        <div class="flex items-center justify-between gap-4 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-ink">{{ $order->reference_code ?? ('Order #'.$order->id) }}</p>
                                <p class="mt-0.5 text-xs text-ink-soft">
                                    {{ $order->supplier_name ?? $order->company?->name }} &middot; {{ $order->money($order->total_amount) }}
                                </p>
                            </div>
                            <x-filament::badge :color="$order->status->color()">
                                {{ $order->status->label() }}
                            </x-filament::badge>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- Trade assurance milestones --}}
        <x-filament::section>
            <x-slot name="heading">Milestones awaiting your confirmation</x-slot>

            @if($milestones->isEmpty())
                <p class="text-sm text-ink-soft">No trade-assurance milestones need your confirmation right now.</p>
            @else
                <div class="divide-y divide-sand-200 -my-6">
                    @foreach($milestones as $milestone)
                        <div class="flex items-center justify-between gap-4 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-ink">{{ $milestone->title }}</p>
                                <p class="mt-0.5 text-xs text-ink-soft">
                                    Order {{ $milestone->agreement?->order?->reference_code ?? ('#'.$milestone->agreement?->order_id) }}
                                </p>
                            </div>
                            <x-filament::badge>{{ $milestone->status->label() }}</x-filament::badge>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
