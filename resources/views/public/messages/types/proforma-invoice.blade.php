{{--
    PROFORMA INVOICE card (mockup: "ORDER CREATED" + the proforma attachment).

    ⚠ Read this before changing any wording here.

    A proforma invoice on this platform is a DOCUMENT VIEW of the order snapshot
    and nothing else. Every figure below was copied by OrderService at award
    time and is reproduced verbatim from the message payload; no new financial
    field is invented for it.

    In particular it is NOT:
      - a tax invoice. The platform runs no fiscal system, issues no tax
        identifiers and computes no tax it was not given. `tax_amount` is
        whatever the supplier put on the quote, and it is shown only if it is
        non-zero, labelled as the supplier's own figure.
      - an invoice series. There is no "INV-2024-0976" here, because minting one
        would imply an invoicing ledger this platform does not keep. The
        document is identified by the order's own reference.
      - a demand for payment. That is the separate payment-request card.

    The mockup's "Download Proforma Invoice" (a PDF) is a PRINT link instead:
    no PDF library was added, and printing the sheet produces the same document.

    Live half: the status pill, read off the related Order on every render.
--}}
@php
    /** @var \App\Models\Order|null $order */
    $order = $message->related;

    $currency = $message->payloadValue('currency');
    $items = (array) $message->payloadValue('items', []);
    $tax = (float) $message->payloadValue('tax_amount', 0);
    $shipping = (float) $message->payloadValue('shipping_amount', 0);
@endphp

<div class="py-1" id="m{{ $message->getKey() }}">
    <div class="mb-2 flex items-center gap-3">
        <span class="h-px flex-1 bg-sand-300"></span>
        <span class="text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-ink-soft">Proforma invoice</span>
        <span class="h-px flex-1 bg-sand-300"></span>
    </div>

    <div class="rounded-2xl border border-sand-200 bg-white p-4 shadow-sm">
        <div class="flex flex-wrap items-start gap-2.5">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-forest-700 text-white">
                <x-heroicon-o-document-text class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="font-display text-[0.9375rem] font-bold text-forest-950">
                    Proforma invoice for order {{ $message->payloadValue('reference_code') }}
                </p>
                <p class="text-[0.75rem] text-ink-soft">
                    @if ($iso = $message->payloadValue('issued_at'))
                        Prepared {{ \Illuminate\Support\Carbon::parse($iso)->isoFormat('D MMM YYYY, h:mm A') }}
                    @endif
                </p>
            </div>
            {{-- LIVE: read from the order, not from the payload. --}}
            @if ($order)
                <x-account.status-pill :label="$order->status->label()" :color="$order->status->color()" />
            @endif
        </div>

        {{-- ---------------------------------------------------- parties --}}
        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 border-t border-sand-200 pt-3 text-[0.8125rem]">
            <div>
                <dt class="text-ink-soft">Supplier</dt>
                <dd class="font-medium text-ink">{{ $message->payloadValue('supplier_name') }}</dd>
            </div>
            <div>
                <dt class="text-ink-soft">Buyer</dt>
                <dd class="font-medium text-ink">
                    {{ $message->payloadValue('buyer_company') ?: $message->payloadValue('buyer_name') }}
                </dd>
            </div>
        </dl>

        {{-- ------------------------------------------------------ lines --}}
        @if ($items !== [])
            <div class="mt-3 overflow-x-auto border-t border-sand-200 pt-3">
                <table class="w-full min-w-[26rem] text-left text-[0.8125rem]">
                    <thead>
                        <tr class="text-[0.6875rem] uppercase tracking-wide text-ink-soft">
                            <th class="pb-1.5 font-semibold">Description</th>
                            <th class="pb-1.5 text-right font-semibold">Qty</th>
                            <th class="pb-1.5 text-right font-semibold">Unit price</th>
                            <th class="pb-1.5 text-right font-semibold">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sand-200">
                        @foreach ($items as $item)
                            <tr class="align-top">
                                <td class="py-2 pr-3">
                                    <span class="font-medium text-ink">{{ $item['description'] ?? $item['species_name'] ?? '—' }}</span>
                                    @php
                                        $spec = array_filter([
                                            $item['grade'] ?? null,
                                            $item['dimensions'] ?? null,
                                        ]);
                                    @endphp
                                    @if ($spec !== [])
                                        <span class="block text-[0.75rem] text-ink-soft">{{ implode(' · ', $spec) }}</span>
                                    @endif
                                </td>
                                <td class="py-2 text-right whitespace-nowrap text-ink">
                                    {{ rtrim(rtrim(number_format((float) ($item['quantity'] ?? 0), 2, '.', ''), '0'), '.') }}
                                    <span class="text-ink-soft">{{ $item['unit'] ?? '' }}</span>
                                </td>
                                <td class="py-2 text-right whitespace-nowrap text-ink">{{ number_format((float) ($item['unit_price'] ?? 0), 2) }}</td>
                                <td class="py-2 text-right whitespace-nowrap font-semibold text-ink">{{ number_format((float) ($item['line_total'] ?? 0), 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- ---------------------------------------------------- totals --}}
        <dl class="mt-3 space-y-1.5 border-t border-sand-200 pt-3 text-[0.8125rem]">
            <div class="flex items-center justify-between gap-4">
                <dt class="text-ink-soft">Subtotal</dt>
                <dd class="font-medium text-ink">{{ $currency }} {{ number_format((float) $message->payloadValue('subtotal_amount', 0), 2) }}</dd>
            </div>
            {{-- Shown only when the supplier actually quoted one. A zero line
                 would read as a considered figure rather than an absent one. --}}
            @if ($shipping > 0)
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-ink-soft">Shipping (as quoted)</dt>
                    <dd class="font-medium text-ink">{{ $currency }} {{ number_format($shipping, 2) }}</dd>
                </div>
            @endif
            @if ($tax > 0)
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-ink-soft">Tax (as stated by the supplier)</dt>
                    <dd class="font-medium text-ink">{{ $currency }} {{ number_format($tax, 2) }}</dd>
                </div>
            @endif
            <div class="flex items-center justify-between gap-4 border-t border-sand-200 pt-1.5">
                <dt class="font-semibold text-ink">Total</dt>
                <dd class="font-display text-[1rem] font-bold text-forest-900">{{ $currency }} {{ number_format((float) $message->payloadValue('total_amount', 0), 2) }}</dd>
            </div>
        </dl>

        {{-- ------------------------------------------------------ terms --}}
        @php
            $terms = array_filter([
                'Delivery terms' => $message->payloadValue('incoterm')
                    ? (\App\Enums\RfqIncoterm::tryFrom((string) $message->payloadValue('incoterm'))?->label() ?? null)
                    : null,
                'Destination port' => $message->payloadValue('shipping_port'),
                'Payment terms' => $message->payloadValue('payment_terms'),
                'Lead time' => $message->payloadValue('lead_time_days')
                    ? $message->payloadValue('lead_time_days').' days after confirmation'
                    : null,
            ]);
        @endphp
        @if ($terms !== [])
            <dl class="mt-3 space-y-1.5 border-t border-sand-200 pt-3 text-[0.8125rem]">
                @foreach ($terms as $label => $value)
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-ink-soft">{{ $label }}</dt>
                        <dd class="text-right font-medium text-ink">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        {{-- The sentence that keeps the rest of the card true. --}}
        <p class="mt-3 rounded-xl bg-sand-50 p-3 text-[0.75rem] leading-relaxed text-ink-soft">
            This proforma restates the agreed order as it was recorded when the quotation was
            accepted. It is not a tax invoice, carries no tax identification, and is not a
            receipt or evidence of payment.
        </p>

        @if ($order)
            <a href="{{ route('chat.order.proforma.sheet', [$conversation, $order]) }}"
               class="mt-3 flex items-center justify-center gap-2 rounded-xl border border-sand-200 px-3.5 py-2.5 text-[0.875rem] font-semibold text-forest-700 transition hover:bg-sand-50">
                <x-heroicon-m-printer class="h-4 w-4" />
                View / print proforma
            </a>
        @endif

        <p class="mt-2 text-right text-[0.6875rem] text-ink-soft">{{ $message->created_at->format('g:i A') }}</p>
    </div>
</div>
