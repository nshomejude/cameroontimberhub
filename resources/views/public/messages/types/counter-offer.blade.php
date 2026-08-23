{{--
    NEGOTIATION / COUNTER-OFFER card (mockup: "Supplier Quote Received in Chat
    mobile", which despite its filename is the negotiation screen).

    The mockup stacks three panes in one card — supplier's offer, buyer's
    counter, supplier's reply — with Reject / Counter Again / Accept Offer at
    the bottom. Here each ROUND is its own card in the transcript, in the order
    it happened, which is the same information rendered as a conversation
    rather than as a growing accordion. That is deliberate: the thread already
    is the chronology, and re-rendering every earlier round inside every later
    one would make the same figures appear three times, each a candidate to
    drift out of step with the ledger.

    Figures: snapshot from the payload. Round STATUS: live from the related
    QuoteCounterOffer, so the buttons vanish the instant the other side answers,
    on both screens, without a new message being written.

    `note` is free text typed by either party and is escaped like any other
    prose. No {!! !!} anywhere in messaging.
--}}
@php
    /** @var \App\Models\QuoteCounterOffer|null $offer */
    $offer = $message->related;

    $currency = $message->payloadValue('currency');
    $money = fn ($amount) => $currency.' '.number_format((float) $amount, 2);

    $fromBuyer = $message->payloadValue('party') === 'buyer';
    $viewerParty = $isBuyer ? 'buyer' : 'supplier';

    // The single rule, mirrored from QuoteCounterOffer::awaitingParty(): only
    // the side that owes an answer sees the buttons. The service re-checks.
    $canRespond = $offer
        && $offer->isPending()
        && $offer->awaitingParty() === $viewerParty;

    $label = $fromBuyer ? "Buyer's counter-offer" : "Supplier's counter-offer";
@endphp

<div class="py-1" id="m{{ $message->getKey() }}">
    <div class="overflow-hidden rounded-2xl border border-sand-200 bg-white shadow-sm">

        <div class="flex flex-wrap items-center gap-2.5 border-b border-sand-200 px-4 py-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-timber-600 text-white">
                <x-heroicon-o-arrows-right-left class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <p class="font-display text-[1.125rem] font-bold text-forest-950">{{ $label }}</p>
                @if ($message->payloadValue('quote_reference'))
                    <p class="text-[0.9375rem] text-ink-soft">On quotation {{ $message->payloadValue('quote_reference') }}</p>
                @endif
            </div>
            @if ($offer)
                <x-account.status-pill class="ml-auto" :label="$offer->status->label()" :color="$offer->status->color()" />
            @endif
        </div>

        <dl @class([
            'grid grid-cols-2 gap-x-4 gap-y-3 px-4 py-3 text-[1.0625rem]',
            'bg-sky-50/60' => $fromBuyer,
            'bg-timber-50/60' => ! $fromBuyer,
        ])>
            @if ($message->payloadValue('quantity'))
                <div>
                    <dt class="text-ink-soft">Quantity</dt>
                    <dd class="font-semibold text-ink">
                        {{ $message->payloadValue('quantity') }} {{ $message->payloadValue('unit_label') ?? $message->payloadValue('unit') }}
                    </dd>
                </div>
            @endif
            <div>
                <dt class="text-ink-soft">{{ $fromBuyer ? 'Target unit price' : 'Proposed unit price' }}</dt>
                <dd class="font-semibold text-ink">{{ $money($message->payloadValue('unit_price')) }}</dd>
            </div>
            <div>
                <dt class="text-ink-soft">{{ $fromBuyer ? 'Target total' : 'Total amount' }}</dt>
                <dd class="font-semibold text-ink">{{ $money($message->payloadValue('total_amount')) }}</dd>
            </div>
            @if ($message->payloadValue('payment_terms'))
                <div>
                    <dt class="text-ink-soft">Payment terms</dt>
                    <dd class="font-medium text-ink">{{ $message->payloadValue('payment_terms') }}</dd>
                </div>
            @endif
            @if ($message->payloadValue('lead_time_days') !== null)
                <div>
                    <dt class="text-ink-soft">Lead time</dt>
                    <dd class="font-medium text-ink">{{ $message->payloadValue('lead_time_days') }} days</dd>
                </div>
            @endif
        </dl>

        @if ($note = $message->payloadValue('note'))
            <p class="whitespace-pre-line break-words border-t border-sand-200 px-4 py-3 text-[1.0625rem] text-ink">{{ $note }}</p>
        @endif

        <div class="border-t border-sand-200 bg-sand-50 px-4 py-3">
            @if ($canRespond)
                <div class="flex flex-wrap gap-2">
                    <button type="button"
                            wire:click="respondToCounter({{ $offer->getKey() }}, 'decline')"
                            wire:confirm="Decline this counter-offer?"
                            class="flex flex-1 items-center justify-center gap-2 rounded-xl border border-red-300 bg-white px-3 py-2.5 text-[1.0625rem] font-bold text-red-700 transition hover:bg-red-50">
                        <x-heroicon-o-x-mark class="h-4 w-4" /> Decline
                    </button>

                    <button type="button"
                            wire:click="openCounter({{ $offer->quote_id }})"
                            class="flex flex-1 items-center justify-center gap-2 rounded-xl border border-forest-700 bg-white px-3 py-2.5 text-[1.0625rem] font-bold text-forest-700 transition hover:bg-forest-50">
                        <x-heroicon-o-arrow-path class="h-4 w-4" /> Counter again
                    </button>

                    <button type="button"
                            wire:click="respondToCounter({{ $offer->getKey() }}, 'accept')"
                            wire:confirm="Accept these terms? A revised quotation at {{ $money($message->payloadValue('unit_price')) }} per unit will be issued and the current one replaced."
                            class="flex flex-1 items-center justify-center gap-2 rounded-xl bg-forest-800 px-3 py-2.5 text-[1.0625rem] font-bold text-white transition hover:bg-forest-900">
                        <x-heroicon-o-check-circle class="h-4 w-4" /> Accept offer
                    </button>
                </div>
            @elseif ($offer && $offer->isPending())
                {{-- Honest, and the reason self-acceptance is impossible: the
                     proposer is never the party who owes the answer. --}}
                <p class="text-center text-[1.0625rem] text-ink-soft">Awaiting a reply from the {{ $offer->awaitingParty() }}.</p>
            @elseif ($offer)
                <p class="text-center text-[1.0625rem] text-ink-soft">
                    {{ $offer->status->label() }}{{ $offer->responded_at ? ' on '.$offer->responded_at->isoFormat('D MMM YYYY, h:mm A') : '' }}.
                    @if ($offer->resulting_quote_id)
                        A revised quotation was issued.
                    @endif
                </p>
            @endif
        </div>

        <p class="px-4 pb-3 text-right text-[0.875rem] text-ink-soft">{{ $message->created_at->format('g:i A') }}</p>
    </div>
</div>
