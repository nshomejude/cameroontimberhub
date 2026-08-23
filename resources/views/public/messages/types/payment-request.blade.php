{{--
    PAYMENT REQUEST card.

    ⚠ THIS PLATFORM PROCESSES NO PAYMENTS. Read this before adding a control.

    The mockup shows a "Pay Now" button and a "Share Payment Proof" upload. The
    first is deliberately absent: there is no payment provider, no checkout, no
    escrow and no wallet behind this platform, so a "Pay Now" button could only
    lead somewhere that either does nothing or — far worse — collects card and
    bank details into a system with nothing to do with them. Nothing on this
    card ever asks for a card number, an account number, a bank credential or a
    mobile-money PIN.

    What this card actually is: a statement of what is owed, on what terms, and
    where the supplier says to send it. Every value is real —

      snapshot (payload) : the total and the balance as they stood when the
                           request was made, and the supplier's payment terms
      live (Order)       : the settlement state and today's outstanding balance
      live (Company)     : the supplier's own published payment instructions

    The 50%/advance split in the mockup is not reproduced. `payment_terms` is
    free text the supplier wrote; parsing a percentage out of it and presenting
    the result as "Amount Due (50% Advance)" would be the platform inventing a
    figure. The terms are shown verbatim instead, next to the real balance.
--}}
@php
    /** @var \App\Models\Order|null $order */
    $order = $message->related;

    $currency = $message->payloadValue('currency');
    $paid = $order && $order->payment_status !== \App\Enums\OrderPaymentStatus::Unpaid;
    $instructions = $order?->company?->payment_instructions;
@endphp

<div class="py-1" id="m{{ $message->getKey() }}">
    <div class="mb-2 flex items-center gap-3">
        <span class="h-px flex-1 bg-sand-300"></span>
        <span class="text-[0.875rem] font-bold uppercase tracking-[0.14em] text-ink-soft">Payment request</span>
        <span class="h-px flex-1 bg-sand-300"></span>
    </div>

    <div class="rounded-2xl border border-amber-200 bg-amber-50/60 p-4 shadow-sm">
        <div class="flex flex-wrap items-start gap-2.5">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-500 text-white">
                <x-heroicon-o-banknotes class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="font-display text-[1.125rem] font-bold text-forest-950">
                    Payment requested for {{ $message->payloadValue('reference_code') }}
                </p>
                {{-- LIVE settlement state, straight off the order. --}}
                @if ($order)
                    <p class="text-[0.9375rem] text-ink-soft">{{ $order->payment_status->label() }}</p>
                @endif
            </div>
        </div>

        <dl class="mt-3 space-y-2 border-t border-amber-200 pt-3 text-[1.0625rem]">
            <div class="flex items-center justify-between gap-4">
                <dt class="text-ink-soft">Order total</dt>
                <dd class="font-medium text-ink">{{ $currency }} {{ number_format((float) $message->payloadValue('total_amount', 0), 2) }}</dd>
            </div>

            {{-- LIVE balance: recomputed from amount_paid every render, so this
                 number falls as payments are recorded. --}}
            @if ($order)
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-ink-soft">Outstanding today</dt>
                    <dd class="font-display text-[1rem] font-bold text-forest-900">{{ $currency }} {{ number_format((float) $order->balanceDue(), 2) }}</dd>
                </div>
            @endif

            @if ($order?->payment_due_at)
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-ink-soft">Requested by</dt>
                    <dd class="font-medium text-ink">{{ $order->payment_due_at->isoFormat('D MMM YYYY') }}</dd>
                </div>
            @endif

            @if ($reference = $order?->payment_reference)
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-ink-soft">Payment reference</dt>
                    <dd class="text-right font-mono text-[1.0625rem] font-medium text-ink">{{ $reference }}</dd>
                </div>
            @endif

            @if ($terms = $message->payloadValue('payment_terms'))
                <div>
                    <dt class="text-ink-soft">Payment terms (as quoted)</dt>
                    <dd class="mt-0.5 font-medium text-ink">{{ $terms }}</dd>
                </div>
            @endif
        </dl>

        {{-- The supplier's own instructions, rendered only when they have
             published some. Escaped, and wrapped rather than linkified. --}}
        @if (trim((string) $instructions) !== '')
            <div class="mt-3 rounded-xl border border-amber-200 bg-white p-3">
                <p class="text-[0.875rem] font-bold uppercase tracking-wide text-ink-soft">How this supplier asks to be paid</p>
                <p class="mt-1 whitespace-pre-line text-[1.0625rem] leading-relaxed text-ink">{{ $instructions }}</p>
            </div>
        @endif

        {{-- Not fine print: the sentence that stops this card being a lie. --}}
        <p class="mt-3 rounded-xl bg-white/80 p-3 text-[0.9375rem] leading-relaxed text-ink-soft">
            Cameroon Timber Hub does not process payments and holds no funds. Settle directly
            with the supplier using the details they have given you, and never send payment
            credentials through this conversation. Once the supplier records your payment it
            will appear here.
        </p>

        <p class="mt-2 text-right text-[0.875rem] text-ink-soft">{{ $message->created_at->format('g:i A') }}</p>
    </div>

    {{-- ------------------------------------------------- supplier action --}}
    {{--
        The supplier records a payment that reached them off-platform. The buyer
        never sees this form, and OrderLifecycleService::recordPayment() refuses
        the buyer outright regardless of what is rendered — a buyer who could
        mark their own order paid would make the settlement state worthless.
    --}}
    @if (! $isBuyer && $order && ! $order->status->isTerminal())
        <div class="mt-2">
            @if ($paymentForOrderId === $order->getKey())
                <form wire:submit.prevent="savePayment" class="rounded-2xl border border-sand-200 bg-white p-4">
                    <p class="font-display text-[1.0625rem] font-bold text-forest-950">Record a payment you have received</p>
                    <p class="mt-1 text-[0.9375rem] text-ink-soft">
                        This records money that already reached you elsewhere. Do not enter bank or
                        card details — only the amount and how it arrived.
                    </p>

                    <label class="mt-3 block text-[0.9375rem] font-semibold text-ink">
                        Amount received ({{ $order->currency->value }})
                        <input type="number" step="0.01" min="0" wire:model="paymentForm.amount"
                               class="mt-1 w-full rounded-xl border border-sand-300 px-3 py-2 text-[1.0625rem]">
                    </label>

                    <label class="mt-2 block text-[0.9375rem] font-semibold text-ink">
                        How it arrived (optional)
                        <input type="text" maxlength="80" wire:model="paymentForm.method" placeholder="e.g. Bank transfer"
                               class="mt-1 w-full rounded-xl border border-sand-300 px-3 py-2 text-[1.0625rem]">
                    </label>

                    {{-- $errors->first(), never @error: the @error directive
                         binds its own $message and would shadow the Message
                         model this partial is rendering. --}}
                    @if ($errors->has('paymentForm.amount'))
                        <p class="mt-2 text-[0.9375rem] font-medium text-red-700">{{ $errors->first('paymentForm.amount') }}</p>
                    @endif

                    <div class="mt-3 flex gap-2">
                        <button type="submit" class="flex-1 rounded-xl bg-forest-700 px-3.5 py-2.5 text-[1.0625rem] font-semibold text-white">
                            Record payment
                        </button>
                        <button type="button" wire:click="cancelPayment" class="rounded-xl border border-sand-300 px-3.5 py-2.5 text-[1.0625rem] font-semibold text-ink-soft">
                            Cancel
                        </button>
                    </div>
                </form>
            @else
                <button type="button" wire:click="openPayment({{ $order->getKey() }})"
                        class="w-full rounded-xl border border-sand-200 bg-white px-3.5 py-2.5 text-[1.0625rem] font-semibold text-forest-700 transition hover:bg-sand-50">
                    Record a payment received
                </button>
            @endif
        </div>
    @endif
</div>
