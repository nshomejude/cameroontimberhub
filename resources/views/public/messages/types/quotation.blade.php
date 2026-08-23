{{--
    QUOTATION card (mockups: "Quotation shortcode in MOBILE message" and
    "RFQ from Chat mobile" — the same card, the second with a Counter Offer
    control between the two).

    The Phase 1 contract, applied literally:

      * every FIGURE comes from $message->payload — the snapshot taken when the
        card was posted. A supplier editing their quote afterwards, or a
        negotiation revising it, cannot rewrite what the buyer was shown here.
      * STATUS and the validity countdown come from the related Quote, read
        live on every render and every 10s poll. An expired, withdrawn, revised
        or accepted quote therefore updates this card in place — no second
        message is written, and the message row is not touched.

    Actions are rendered only for the side entitled to them, but the view is
    not the gate: ChatCommerceService re-checks on every action, so removing a
    `disabled` in devtools buys nothing.

    Not rendered, because there is no data behind it: any "you save X%",
    "best value" or competitor comparison. The countdown is real — it is
    `valid_until`, a column the supplier filled in.
--}}
@php
    /** @var \App\Models\Quote|null $quote */
    $quote = $message->related;

    $currency = $message->payloadValue('currency');
    $items = (array) $message->payloadValue('items', []);
    $money = fn ($amount) => $currency.' '.number_format((float) $amount, 2);

    // Live, not snapshotted.
    $daysLeft = $quote?->daysRemaining();
    $actionable = $quote?->isActionable() ?? false;
    $countering = $quote && $counteringQuoteId !== null && (int) $counteringQuoteId === (int) $quote->getKey();

    $printUrl = ($quote && $isBuyer && $quote->rfq)
        ? app(\App\Services\BuyerRfqAccess::class)->link(request(), 'quote', $quote->rfq, $quote)
        : null;
@endphp

<div class="py-1" id="m{{ $message->getKey() }}">
    <div class="overflow-hidden rounded-2xl border border-sand-200 bg-white shadow-sm">

        {{-- ------------------------------------------------------- header --}}
        <div class="flex flex-wrap items-center gap-2.5 border-b border-sand-200 px-4 py-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-forest-700 text-white">
                <x-heroicon-o-document-currency-dollar class="h-5 w-5" />
            </span>

            <div class="min-w-0">
                <p class="font-display text-[1.125rem] font-bold uppercase tracking-wide text-forest-950">Quotation</p>
                <p class="text-[0.9375rem] font-semibold text-ink-soft">
                    {{ $message->payloadValue('reference_code') }}
                    @if (($rev = (int) $message->payloadValue('revision', 1)) > 1)
                        · Revision {{ $rev }}
                    @endif
                </p>
            </div>

            <div class="ml-auto flex items-center gap-2">
                @if ($quote)
                    <x-account.status-pill :label="$quote->threadStatusLabel()" :color="$quote->threadStatusColor()" />
                @endif
            </div>
        </div>

        {{-- --------------------------------------------------- provenance --}}
        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 border-b border-sand-200 px-4 py-3 text-[1.0625rem]">
            <div>
                <dt class="text-ink-soft">From</dt>
                <dd class="font-semibold text-ink">{{ $message->payloadValue('supplier_name') ?? $conversation->company?->name }}</dd>
            </div>
            @if ($message->payloadValue('rfq_reference'))
                <div>
                    <dt class="text-ink-soft">Request</dt>
                    <dd class="font-medium text-ink">{{ $message->payloadValue('rfq_reference') }}</dd>
                </div>
            @endif
            @if ($iso = $message->payloadValue('submitted_at'))
                <div>
                    <dt class="text-ink-soft">Issued</dt>
                    <dd class="font-medium text-ink">{{ \Illuminate\Support\Carbon::parse($iso)->isoFormat('D MMM YYYY') }}</dd>
                </div>
            @endif
            @if ($validUntil = $message->payloadValue('valid_until'))
                <div>
                    <dt class="text-ink-soft">Expires</dt>
                    <dd class="font-medium text-ink">
                        {{ \Illuminate\Support\Carbon::parse($validUntil)->isoFormat('D MMM YYYY') }}
                        {{-- Real countdown: derived from valid_until, live. --}}
                        @if ($daysLeft !== null)
                            <span @class([
                                'block text-[0.9375rem]',
                                'text-red-700' => $daysLeft === 0,
                                'text-ink-soft' => $daysLeft > 0,
                            ])>
                                @if ($daysLeft === 0) Expires today or has lapsed
                                @elseif ($daysLeft === 1) 1 day left
                                @else {{ $daysLeft }} days left
                                @endif
                            </span>
                        @endif
                    </dd>
                </div>
            @endif
        </dl>

        {{-- -------------------------------------------------------- lines --}}
        <div class="overflow-x-auto">
            <table class="w-full min-w-[30rem] text-left text-[1.0625rem]">
                <thead class="bg-forest-800 text-white">
                    <tr>
                        <th scope="col" class="px-3 py-2 font-semibold">Description</th>
                        <th scope="col" class="px-3 py-2 text-right font-semibold">Qty</th>
                        <th scope="col" class="px-3 py-2 text-right font-semibold">Unit price</th>
                        <th scope="col" class="px-3 py-2 text-right font-semibold">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr class="border-b border-sand-200 last:border-0">
                            <td class="px-3 py-2.5 align-top">
                                <span class="font-semibold text-ink">{{ $item['description'] ?? '—' }}</span>
                                @if (! empty($item['specification']))
                                    <span class="block text-[0.9375rem] text-ink-soft">{{ $item['specification'] }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right align-top text-ink">
                                {{ $item['quantity'] ?? '' }} {{ $item['unit_label'] ?? $item['unit'] ?? '' }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right align-top text-ink">
                                {{ number_format((float) ($item['unit_price'] ?? 0), 2) }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right align-top font-semibold text-ink">
                                {{ number_format((float) ($item['line_total'] ?? 0), 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- -------------------------------------------------------- terms --}}
        <dl class="space-y-2 border-t border-sand-200 px-4 py-3 text-[1.0625rem]">
            <div class="flex items-start justify-between gap-4">
                <dt class="text-ink-soft">Subtotal</dt>
                <dd class="text-right font-medium text-ink">{{ $money($message->payloadValue('subtotal_amount')) }}</dd>
            </div>
            @if ($message->payloadValue('shipping_amount') !== null)
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-ink-soft">Shipping</dt>
                    <dd class="text-right font-medium text-ink">{{ $money($message->payloadValue('shipping_amount')) }}</dd>
                </div>
            @endif
            @if ($message->payloadValue('tax_amount') !== null)
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-ink-soft">Tax</dt>
                    <dd class="text-right font-medium text-ink">{{ $money($message->payloadValue('tax_amount')) }}</dd>
                </div>
            @endif
            @if ($message->payloadValue('incoterm'))
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-ink-soft">Delivery terms</dt>
                    <dd class="text-right font-medium text-ink">{{ $message->payloadValue('incoterm') }}</dd>
                </div>
            @endif
            @if ($message->payloadValue('lead_time_days') !== null)
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-ink-soft">Lead time</dt>
                    <dd class="text-right font-medium text-ink">{{ $message->payloadValue('lead_time_days') }} days after confirmation</dd>
                </div>
            @endif
            @if ($message->payloadValue('payment_terms'))
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-ink-soft">Payment terms</dt>
                    <dd class="text-right font-medium text-ink">{{ $message->payloadValue('payment_terms') }}</dd>
                </div>
            @endif
            @if ($message->payloadValue('notes'))
                <div class="flex items-start justify-between gap-4">
                    <dt class="shrink-0 text-ink-soft">Notes</dt>
                    <dd class="whitespace-pre-line break-words text-right text-ink">{{ $message->payloadValue('notes') }}</dd>
                </div>
            @endif
        </dl>

        <div class="flex items-center justify-between gap-4 border-t border-sand-200 px-4 py-3">
            <p class="font-display text-[1.125rem] font-bold text-forest-800">Grand total</p>
            <p class="font-display text-[1.125rem] font-bold text-forest-900">{{ $money($message->payloadValue('total_amount')) }}</p>
        </div>

        {{-- ------------------------------------------------------ actions --}}
        <div class="space-y-2 border-t border-sand-200 bg-sand-50 px-4 py-3">

            {{-- The mockup's "Download PDF". There is no PDF renderer in this
                 application and adding one is out of scope, so rather than a
                 button that produces a broken or fake file, this is the real
                 quotation document — the same HTML page the emailed link
                 opens — labelled as what it is. The browser's own print dialog
                 turns it into a PDF. --}}
            @if ($printUrl)
                <a href="{{ $printUrl }}"
                   class="flex w-full items-center justify-center gap-2 rounded-xl border border-sand-300 bg-white px-3.5 py-2.5 text-[1.0625rem] font-bold text-forest-700 transition hover:bg-sand-100">
                    <x-heroicon-o-document-text class="h-4 w-4" />
                    View / print quotation
                </a>
            @endif

            @if ($quote && $actionable && $isBuyer)
                <div class="flex flex-wrap gap-2">
                    <button type="button"
                            wire:click="openCounter({{ $quote->getKey() }})"
                            class="flex flex-1 items-center justify-center gap-2 rounded-xl border border-forest-700 bg-white px-3.5 py-2.5 text-[1.0625rem] font-bold text-forest-700 transition hover:bg-forest-50">
                        <x-heroicon-o-arrows-right-left class="h-4 w-4" />
                        Counter offer
                    </button>

                    <button type="button"
                            wire:click="acceptQuote({{ $quote->getKey() }})"
                            wire:confirm="Accept quotation {{ $message->payloadValue('reference_code') }} for {{ $money($message->payloadValue('total_amount')) }}? Every other quotation on this request will be declined and an order will be created. This cannot be undone here."
                            wire:loading.attr="disabled"
                            class="flex flex-1 items-center justify-center gap-2 rounded-xl bg-forest-800 px-3.5 py-2.5 text-[1.0625rem] font-bold text-white transition hover:bg-forest-900 disabled:opacity-60">
                        <x-heroicon-o-check-circle class="h-4 w-4" />
                        Accept quotation
                    </button>
                </div>

                <button type="button"
                        wire:click="declineQuote({{ $quote->getKey() }}, 'Declined from the conversation.')"
                        wire:confirm="Decline this quotation?"
                        class="w-full rounded-xl px-3.5 py-2 text-[1.0625rem] font-semibold text-ink-soft transition hover:text-red-700">
                    Decline
                </button>
            @elseif ($quote && $actionable && ! $isBuyer)
                <button type="button"
                        wire:click="withdrawQuote({{ $quote->getKey() }})"
                        wire:confirm="Withdraw this quotation? The buyer will no longer be able to accept it."
                        class="w-full rounded-xl border border-sand-300 bg-white px-3.5 py-2.5 text-[1.0625rem] font-bold text-ink-soft transition hover:text-red-700">
                    Withdraw quotation
                </button>
                <p class="text-center text-[0.9375rem] text-ink-soft">Only the buyer can accept or decline a quotation.</p>
            @elseif ($quote)
                {{-- Settled. State the fact, offer nothing to press. --}}
                <p class="text-center text-[1.0625rem] text-ink-soft">
                    @if ($quote->status === \App\Enums\QuoteStatus::Accepted)
                        Accepted{{ $quote->decided_at ? ' on '.$quote->decided_at->isoFormat('D MMM YYYY') : '' }}.
                    @elseif ($quote->isSuperseded())
                        Replaced by a revised quotation after negotiation.
                    @elseif ($quote->isExpired())
                        This quotation has expired and can no longer be accepted.
                    @else
                        {{ $quote->threadStatusLabel() }}. No further action is possible.
                    @endif
                </p>
            @endif
        </div>

        {{-- ------------------------------------------- counter-offer form --}}
        @if ($countering)
            <form wire:submit.prevent="submitCounter" class="space-y-3 border-t border-sand-200 bg-white px-4 py-3">
                <p class="font-display text-[1.0625rem] font-bold text-forest-950">Propose different terms</p>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="counter-price-{{ $message->getKey() }}" class="block text-[0.9375rem] font-semibold text-ink-soft">
                            Target unit price ({{ $currency }})<span class="text-red-600">*</span>
                        </label>
                        <input id="counter-price-{{ $message->getKey() }}" type="number" step="0.01" min="0.01"
                               wire:model="counterForm.unit_price"
                               class="mt-1 w-full rounded-xl border border-sand-300 bg-sand-50 px-3 py-2 text-[1.0625rem] outline-none focus:border-forest-500">
                    </div>
                    <div>
                        <label for="counter-qty-{{ $message->getKey() }}" class="block text-[0.9375rem] font-semibold text-ink-soft">Quantity</label>
                        <input id="counter-qty-{{ $message->getKey() }}" type="number" step="0.01" min="0.01"
                               wire:model="counterForm.quantity"
                               placeholder="{{ $items[0]['quantity'] ?? '' }}"
                               class="mt-1 w-full rounded-xl border border-sand-300 bg-sand-50 px-3 py-2 text-[1.0625rem] outline-none focus:border-forest-500">
                    </div>
                </div>

                <div>
                    <label for="counter-terms-{{ $message->getKey() }}" class="block text-[0.9375rem] font-semibold text-ink-soft">Payment terms</label>
                    <input id="counter-terms-{{ $message->getKey() }}" type="text" maxlength="255"
                           wire:model="counterForm.payment_terms"
                           placeholder="{{ $message->payloadValue('payment_terms') }}"
                           class="mt-1 w-full rounded-xl border border-sand-300 bg-sand-50 px-3 py-2 text-[1.0625rem] outline-none focus:border-forest-500">
                </div>

                <div>
                    <label for="counter-note-{{ $message->getKey() }}" class="block text-[0.9375rem] font-semibold text-ink-soft">Message</label>
                    <textarea id="counter-note-{{ $message->getKey() }}" rows="2" maxlength="1000"
                              wire:model="counterForm.note"
                              class="mt-1 w-full resize-y rounded-xl border border-sand-300 bg-sand-50 px-3 py-2 text-[1.0625rem] outline-none focus:border-forest-500"></textarea>
                </div>

                {{-- $errors->first(), not @error: @error would bind its own
                     $message and shadow the Message model this partial is
                     rendering. --}}
                @if ($counterError = $errors->first('counterForm.unit_price'))
                    <p class="text-[1.0625rem] font-medium text-red-700">{{ $counterError }}</p>
                @endif

                <div class="flex gap-2">
                    <button type="button" wire:click="cancelCounter"
                            class="flex-1 rounded-xl border border-sand-300 px-3.5 py-2.5 text-[1.0625rem] font-bold text-ink-soft transition hover:bg-sand-100">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 rounded-xl bg-forest-800 px-3.5 py-2.5 text-[1.0625rem] font-bold text-white transition hover:bg-forest-900">
                        Send counter-offer
                    </button>
                </div>
            </form>
        @endif

        <p class="px-4 pb-3 text-right text-[0.875rem] text-ink-soft">{{ $message->created_at->format('g:i A') }}</p>
    </div>
</div>
