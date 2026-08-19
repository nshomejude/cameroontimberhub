{{--
    ORDER REFERENCE card.

    Money and identity come from the message payload — the snapshot taken when
    the card was posted, so the history of the conversation cannot be rewritten.
    The STATUS pill comes from the related Order row, read live on every render
    (and on every 10s poll), so the card always shows where the order is today.
--}}
@php
    $order = $message->related;
    $isBuyer = (int) $conversation->user_id === (int) $user->getKey();
    $orderUrl = ($order && $isBuyer && $order->rfq)
        ? app(\App\Services\BuyerRfqAccess::class)->link(request(), 'order', $order->rfq)
        : null;
@endphp

<div class="py-1">
    <div class="mb-2 flex items-center gap-3">
        <span class="h-px flex-1 bg-sand-300"></span>
        <span class="text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-ink-soft">Order reference</span>
        <span class="h-px flex-1 bg-sand-300"></span>
    </div>

    <div class="rounded-2xl border border-sand-200 bg-white p-4 shadow-sm">
        <div class="flex flex-wrap items-center gap-2.5">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-forest-700 text-white">
                <x-heroicon-o-shopping-cart class="h-5 w-5" />
            </span>
            <p class="font-display text-[1rem] font-bold text-forest-950">
                Order #{{ $message->payloadValue('reference_code') }}
            </p>
            @if ($order)
                <x-account.status-pill :label="$order->status->label()" :color="$order->status->color()" />
            @endif
            {{-- The mockup's "Reorder ⟳" chip. Backed by a real column: this
                 order was awarded from a quote against a reorder request. It is
                 provenance only — no figure on this order was inherited. --}}
            @if ($order?->isReorder())
                <span class="inline-flex items-center gap-1 rounded-full bg-forest-50 px-2.5 py-1 text-[0.6875rem] font-bold text-forest-800 ring-1 ring-inset ring-forest-200">
                    <x-heroicon-o-arrow-path class="h-3 w-3" />
                    Reorder
                </span>
            @endif
        </div>

        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-sand-200 pt-3 text-[0.8125rem]">
            <div>
                <dt class="text-ink-soft">Order date</dt>
                <dd class="font-medium text-ink">
                    {{ ($iso = $message->payloadValue('ordered_at')) ? \Illuminate\Support\Carbon::parse($iso)->isoFormat('D MMM YYYY, h:mm A') : '—' }}
                </dd>
            </div>
            <div>
                <dt class="text-ink-soft">Total amount</dt>
                <dd class="font-semibold text-ink">
                    {{ $message->payloadValue('currency') }} {{ number_format((float) $message->payloadValue('total_amount'), 2) }}
                </dd>
            </div>
            <div>
                <dt class="text-ink-soft">Item</dt>
                <dd class="font-medium text-ink">
                    {{ $message->payloadValue('item_description') ?? '—' }}
                    @if ($message->payloadValue('item_quantity'))
                        <span class="block text-ink-soft">{{ $message->payloadValue('item_quantity') }} {{ $message->payloadValue('item_unit') }}</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-ink-soft">Supplier</dt>
                <dd class="font-medium text-ink">{{ $message->payloadValue('supplier_name') }}</dd>
            </div>
        </dl>

        @if ($orderUrl)
            <a href="{{ $orderUrl }}"
               class="mt-3 flex items-center gap-2 rounded-xl border border-sand-200 px-3.5 py-2.5 text-[0.875rem] font-semibold text-forest-700 transition hover:bg-sand-50">
                <x-heroicon-o-document-text class="h-4 w-4" />
                View order details
                <x-heroicon-m-chevron-right class="ml-auto h-4 w-4" />
            </a>
        @endif

        <p class="mt-2 text-right text-[0.6875rem] text-ink-soft">{{ $message->created_at->format('g:i A') }}</p>
    </div>

    {{-- Reorder, surfaced on the order card itself once the goods have landed.
         Draws nothing unless this viewer is the buyer and the order is
         genuinely eligible — the partial asks ReorderService, and the service
         refuses the action regardless of what was drawn. --}}
    @if ($order)
        @include('public.messages.partials.reorder-prompt', [
            'order' => $order,
            'isBuyer' => $isBuyer,
            'user' => $user,
            'conversation' => $conversation,
            'reorderForOrderId' => $reorderForOrderId ?? null,
        ])
    @endif
</div>
