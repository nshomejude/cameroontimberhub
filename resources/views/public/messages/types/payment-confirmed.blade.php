{{--
    PAYMENT CONFIRMED card.

    ⚠ This card reports a payment the SUPPLIER says reached them off-platform.
    Cameroon Timber Hub moved no money, verified no transfer and holds no funds.
    The wording is therefore "recorded by", in the past tense, naming the person
    who recorded it — never "payment successful", never "transaction complete",
    and never a green tick standing alone with no attribution.

    The mockup's "Transaction ID: TXN-2024-33457" is NOT reproduced: there is no
    payment processor to issue one, so it could only be a fabricated identifier.
    The supplier's own free-text reference is shown instead when they gave one.

    Live half: `payment_status`, `amount_paid` and the outstanding balance are
    read off the Order every render, so if a later payment is recorded — or a
    correction reduces it — this card follows.
--}}
@php
    /** @var \App\Models\Order|null $order */
    $order = $message->related;

    $currency = $message->payloadValue('currency');
@endphp

<div class="py-1" id="m{{ $message->getKey() }}">
    <div class="mb-2 flex items-center gap-3">
        <span class="h-px flex-1 bg-sand-300"></span>
        <span class="text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-ink-soft">Payment recorded</span>
        <span class="h-px flex-1 bg-sand-300"></span>
    </div>

    <div class="rounded-2xl border border-forest-200 bg-forest-50/60 p-4 shadow-sm">
        <div class="flex flex-wrap items-start gap-2.5">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-forest-700 text-white">
                <x-heroicon-o-check-circle class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="font-display text-[0.9375rem] font-bold text-forest-950">
                    Payment recorded for {{ $message->payloadValue('reference_code') }}
                </p>
                <p class="text-[0.8125rem] text-ink-soft">
                    Recorded by {{ $message->payloadValue('recorded_by_name') }}
                    @if ($iso = $message->payloadValue('recorded_at'))
                        on {{ \Illuminate\Support\Carbon::parse($iso)->isoFormat('D MMM YYYY, h:mm A') }}
                    @endif
                </p>
            </div>
            {{-- LIVE settlement pill. --}}
            @if ($order)
                <x-account.status-pill :label="$order->payment_status->label()" :color="$order->payment_status->color()" />
            @endif
        </div>

        <dl class="mt-3 space-y-2 border-t border-forest-200 pt-3 text-[0.8125rem]">
            <div class="flex items-center justify-between gap-4">
                <dt class="text-ink-soft">Amount recorded</dt>
                <dd class="font-display text-[1rem] font-bold text-forest-900">
                    {{ $currency }} {{ number_format((float) $message->payloadValue('amount_paid', 0), 2) }}
                </dd>
            </div>

            @if ($method = $message->payloadValue('payment_method'))
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-ink-soft">How it arrived</dt>
                    <dd class="font-medium text-ink">{{ $method }}</dd>
                </div>
            @endif

            @if ($reference = $order?->payment_reference)
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-ink-soft">Supplier's reference</dt>
                    <dd class="text-right font-mono text-[0.8125rem] font-medium text-ink">{{ $reference }}</dd>
                </div>
            @endif

            {{-- LIVE total paid and balance, so a part payment reads honestly. --}}
            @if ($order)
                <div class="flex items-center justify-between gap-4 border-t border-forest-200 pt-2">
                    <dt class="text-ink-soft">Total recorded to date</dt>
                    <dd class="font-semibold text-ink">{{ $currency }} {{ number_format((float) $order->amount_paid, 2) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-ink-soft">Outstanding</dt>
                    <dd class="font-semibold text-ink">{{ $currency }} {{ number_format((float) $order->balanceDue(), 2) }}</dd>
                </div>
            @endif
        </dl>

        {{-- The mockup's "Download Receipt" is real: Phase 1 issues a verifiable
             receipt per order. It is linked only when one actually exists and
             only to the buyer, whose access route it is. --}}
        @if ($order && $isBuyer && $order->rfq)
            <a href="{{ app(\App\Services\BuyerRfqAccess::class)->link(request(), 'receipt', $order->rfq) }}"
               class="mt-3 flex items-center justify-center gap-2 rounded-xl border border-forest-200 bg-white px-3.5 py-2.5 text-[0.875rem] font-semibold text-forest-700 transition hover:bg-sand-50">
                <x-heroicon-m-document-text class="h-4 w-4" />
                View / print receipt
            </a>
        @endif

        <p class="mt-3 rounded-xl bg-white/70 p-3 text-[0.75rem] leading-relaxed text-ink-soft">
            This is the supplier's record that payment reached them outside this platform.
            Cameroon Timber Hub did not process, hold or verify the transfer, and this entry is
            not confirmation from a bank or a payment provider.
        </p>

        <p class="mt-2 text-right text-[0.6875rem] text-ink-soft">{{ $message->created_at->format('g:i A') }}</p>
    </div>
</div>
