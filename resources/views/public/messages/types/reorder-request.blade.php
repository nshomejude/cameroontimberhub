{{--
    REORDER REQUEST card.

    Snapshot vs live, the same split every other card uses:

      snapshot (payload) — which order is being repeated, the specification the
        buyer asked for, and the unit prices from LAST TIME;
      live (related Rfq)  — whether the supplier has answered yet.

    The previous prices are the delicate part. They are shown because the buyer
    genuinely paid them and hiding them would make the request unreadable, but
    every one of them is labelled "previous price" and the card states in as
    many words that it is not the price of this order. Nothing on this card is
    an offer: the supplier's own quotation card, posted after they price the
    request, is the offer.

    From the mockup, deliberately NOT built:
      - "Your previous pricing and terms will be applied." An old price is not a
        current offer, and printing that sentence would make the platform assert
        something no supplier has agreed to. Replaced with the honest opposite.
      - "Recommended" / "frequently reordered" badges. Nothing computes those
        and inventing them would be a fabricated endorsement.
      - Estimated delivery dates on the request. The lead time is the
        supplier's to state, and they have not stated one yet.
--}}
@php
    /** @var \App\Models\Rfq|null $rfq */
    $rfq = $message->related;

    $currency = $message->payloadValue('currency');
    $lines = $message->payloadValue('lines') ?? [];

    // Live: has the supplier on this thread put a quote against this request?
    // The quotation itself renders as its own card further down the thread.
    $answered = $rfq
        ? $rfq->quotes->where('company_id', $conversation->company_id)
            ->whereNotIn('status', [\App\Enums\QuoteStatus::Withdrawn, \App\Enums\QuoteStatus::Draft])
            ->isNotEmpty()
        : false;

    $pricing = ! $isBuyer && $rfq && $quotingReorderRfqId === $rfq->getKey();
@endphp

@if ($rfq)
    <div class="py-1" id="m{{ $message->getKey() }}">
        <div class="mb-2 flex items-center gap-3">
            <span class="h-px flex-1 bg-sand-300"></span>
            <span class="text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-ink-soft">Reorder request</span>
            <span class="h-px flex-1 bg-sand-300"></span>
        </div>

        <div class="rounded-2xl border border-sand-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-start gap-2.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-forest-700 text-white">
                    <x-heroicon-o-arrow-path class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="font-display text-[0.9375rem] font-bold text-forest-950">
                        Repeat order {{ $message->payloadValue('source_reference_code') }}
                    </p>
                    <p class="text-[0.8125rem] text-ink-soft">
                        Request {{ $message->payloadValue('rfq_reference_code') }}
                        @if ($iso = $message->payloadValue('source_completed_at'))
                            · previously delivered {{ \Illuminate\Support\Carbon::parse($iso)->isoFormat('D MMM YYYY') }}
                        @endif
                    </p>
                </div>
                <x-account.status-pill :label="$answered ? 'Quoted' : 'Awaiting pricing'"
                                       :color="$answered ? 'success' : 'warning'" />
            </div>

            {{-- The specification being asked for. Quantity is the buyer's to
                 state; price is not, and the column says so. --}}
            <div class="mt-3 overflow-x-auto border-t border-sand-200 pt-3">
                <table class="w-full min-w-[22rem] text-left text-[0.8125rem]">
                    <thead>
                        <tr class="text-[0.6875rem] uppercase tracking-wide text-ink-soft">
                            <th class="pb-1 font-semibold">Item</th>
                            <th class="pb-1 text-right font-semibold">Requested</th>
                            <th class="pb-1 text-right font-semibold">Previous price</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sand-200">
                        @foreach ($lines as $line)
                            <tr>
                                <td class="py-2 pr-2 align-top">
                                    <span class="font-medium text-ink">{{ $line['description'] ?? $line['species_name'] }}</span>
                                    @if (! empty($line['grade']) || ! empty($line['dimensions']))
                                        <span class="block text-[0.75rem] text-ink-soft">
                                            {{ collect([$line['grade'] ?? null, $line['dimensions'] ?? null])->filter()->join(' · ') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="py-2 text-right align-top font-semibold text-ink">
                                    {{ rtrim(rtrim((string) ($line['requested_quantity'] ?? $line['quantity']), '0'), '.') }}
                                    {{ $line['unit'] }}
                                </td>
                                <td class="py-2 text-right align-top text-ink-soft">
                                    {{ $currency }} {{ number_format((float) ($line['previous_unit_price'] ?? 0), 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($port = $message->payloadValue('shipping_port'))
                <p class="mt-2 text-[0.8125rem] text-ink-soft">Delivery port: <span class="font-medium text-ink">{{ $port }}</span></p>
            @endif

            @if ($notes = $message->payloadValue('notes'))
                <p class="mt-2 rounded-xl bg-sand-50 px-3 py-2 text-[0.8125rem] text-ink">{{ $notes }}</p>
            @endif

            {{-- The correction to the mockup's promise, stated plainly on the
                 card itself rather than buried in a tooltip. --}}
            <p class="mt-3 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50/60 px-3 py-2 text-[0.75rem] text-ink">
                <x-heroicon-o-information-circle class="mt-px h-4 w-4 shrink-0 text-amber-600" />
                <span>
                    Prices shown are what was paid on order {{ $message->payloadValue('source_reference_code') }}.
                    They are for reference only — {{ $conversation->company?->name }} must confirm current pricing
                    before there is anything to accept.
                </span>
            </p>

            <p class="mt-2 text-right text-[0.6875rem] text-ink-soft">{{ $message->created_at->format('g:i A') }}</p>
        </div>

        {{-- ------------------------------------------------ supplier pricing --}}
        @if (! $isBuyer && ! $answered)
            @if ($pricing)
                <form wire:submit.prevent="submitReorderQuote" class="mt-2 rounded-2xl border border-forest-200 bg-white p-4">
                    <p class="font-display text-[0.9375rem] font-bold text-forest-950">Confirm your pricing</p>
                    <p class="mt-1 text-[0.75rem] text-ink-soft">
                        Enter today's unit price for each line. Nothing is pre-filled from the previous order —
                        the buyer is accepting the price you state here, not the one they paid last time.
                    </p>

                    @foreach ($rfq->items as $item)
                        <label class="mt-3 block text-[0.75rem] font-semibold text-ink">
                            {{ $item->species_text }}
                            <span class="font-normal text-ink-soft">
                                — {{ rtrim(rtrim((string) $item->quantity, '0'), '.') }} {{ $item->unit }}
                            </span>
                            <span class="mt-1 flex items-center gap-2">
                                <span class="text-[0.75rem] font-semibold text-ink-soft">{{ $rfq->target_currency }}</span>
                                <input type="number" step="0.01" min="0.01" inputmode="decimal"
                                       wire:model="reorderQuoteForm.lines.{{ $item->getKey() }}.unit_price"
                                       class="w-full rounded-xl border border-sand-300 px-3 py-2 text-[0.875rem]"
                                       placeholder="Unit price">
                            </span>
                        </label>
                    @endforeach

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <label class="block text-[0.75rem] font-semibold text-ink">
                            Lead time (days)
                            <input type="number" min="0" max="3650" wire:model="reorderQuoteForm.lead_time_days"
                                   class="mt-1 w-full rounded-xl border border-sand-300 px-3 py-2 text-[0.875rem]">
                        </label>
                        <label class="block text-[0.75rem] font-semibold text-ink">
                            Valid for (days)
                            <input type="number" min="1" max="365" wire:model="reorderQuoteForm.validity_days"
                                   class="mt-1 w-full rounded-xl border border-sand-300 px-3 py-2 text-[0.875rem]">
                        </label>
                        <label class="col-span-2 block text-[0.75rem] font-semibold text-ink">
                            Shipping amount
                            <input type="number" step="0.01" min="0" wire:model="reorderQuoteForm.shipping_amount"
                                   class="mt-1 w-full rounded-xl border border-sand-300 px-3 py-2 text-[0.875rem]">
                        </label>
                        <label class="col-span-2 block text-[0.75rem] font-semibold text-ink">
                            Payment terms
                            <input type="text" maxlength="255" wire:model="reorderQuoteForm.payment_terms"
                                   class="mt-1 w-full rounded-xl border border-sand-300 px-3 py-2 text-[0.875rem]">
                        </label>
                    </div>

                    @foreach ($errors->keys() as $key)
                        @if (\Illuminate\Support\Str::startsWith($key, 'reorderQuoteForm'))
                            <p class="mt-2 text-[0.75rem] font-medium text-red-700">{{ $errors->first($key) }}</p>
                        @endif
                    @endforeach

                    <div class="mt-3 flex gap-2">
                        <button type="submit" class="flex-1 rounded-xl bg-forest-700 px-3.5 py-2.5 text-[0.875rem] font-semibold text-white">
                            Send quotation
                        </button>
                        <button type="button" wire:click="cancelReorderQuote"
                                class="rounded-xl border border-sand-300 px-3.5 py-2.5 text-[0.875rem] font-semibold text-ink-soft">
                            Cancel
                        </button>
                    </div>
                </form>
            @else
                <button type="button" wire:click="openReorderQuote({{ $rfq->getKey() }})"
                        class="mt-2 w-full rounded-xl bg-forest-700 px-3.5 py-2.5 text-[0.875rem] font-semibold text-white">
                    Price this reorder
                </button>
            @endif
        @elseif ($isBuyer && ! $answered)
            <p class="mt-2 rounded-xl border border-sand-200 bg-white px-3.5 py-2.5 text-center text-[0.8125rem] text-ink-soft">
                Waiting for {{ $conversation->company?->name }} to confirm pricing.
            </p>
        @endif
    </div>
@endif
