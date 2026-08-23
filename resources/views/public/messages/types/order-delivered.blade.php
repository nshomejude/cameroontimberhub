{{--
    ORDER DELIVERED card, with proof of delivery.

    The delivery facts (when, who received it, where) are recorded by the
    supplier when they mark the order delivered, and each one renders only if it
    was actually filled in — Order::deliveryFacts() drops the empty ones, so
    there are no "Received By: —" rows.

    Proof of delivery is REAL file storage, not a decorative thumbnail strip:
    files go to the private `documents` disk, have no public URL, and each link
    below goes through OrderDocumentDownloadController, which re-derives
    participation from the order itself and 404s for anyone else. If the
    supplier uploaded nothing, the proof section is absent entirely rather than
    showing placeholder tiles.

    The buyer's "confirm receipt" is what CLOSES the transaction — it is the
    buyer's action, never the supplier's, because completion is what makes a
    review possible and a supplier must not be able to manufacture it.

    Live half: the status, the delivery facts and the document list.
--}}
@php
    /** @var \App\Models\Order|null $order */
    $order = $message->related;

    $facts = $order?->deliveryFacts() ?? [];
    $proof = $order ? $order->documents->filter(fn ($d) => $d->isProofOfDelivery()) : collect();
@endphp

@if ($order)
    <div class="py-1" id="m{{ $message->getKey() }}">
        <div class="mb-2 flex items-center gap-3">
            <span class="h-px flex-1 bg-sand-300"></span>
            <span class="text-[0.875rem] font-bold uppercase tracking-[0.14em] text-ink-soft">Delivery</span>
            <span class="h-px flex-1 bg-sand-300"></span>
        </div>

        <div class="rounded-2xl border border-forest-200 bg-forest-50/60 p-4 shadow-sm">
            <div class="flex flex-wrap items-start gap-2.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-forest-700 text-white">
                    <x-heroicon-o-check-badge class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="font-display text-[1.125rem] font-bold text-forest-950">
                        Delivery recorded for {{ $message->payloadValue('reference_code') }}
                    </p>
                    <p class="text-[1.0625rem] text-ink-soft">{{ $order->status->description() }}</p>
                </div>
                <x-account.status-pill :label="$order->status->label()" :color="$order->status->color()" />
            </div>

            @if ($facts !== [])
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2.5 border-t border-forest-200 pt-3 text-[1.0625rem]">
                    @foreach ($facts as $label => $value)
                        <div>
                            <dt class="text-[0.9375rem] text-ink-soft">{{ $label }}</dt>
                            <dd class="font-medium text-ink">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif

            {{-- --------------------------------------------- proof of delivery --}}
            @if ($proof->isNotEmpty())
                <div class="mt-3 border-t border-forest-200 pt-3">
                    <p class="text-[0.875rem] font-bold uppercase tracking-wide text-ink-soft">Proof of delivery</p>
                    <ul class="mt-2 space-y-1.5">
                        @foreach ($proof as $document)
                            <li>
                                <a href="{{ route('order-documents.download', $document) }}"
                                   class="flex items-center gap-2.5 rounded-xl border border-forest-200 bg-white px-3 py-2 text-[1.0625rem] transition hover:bg-sand-50">
                                    <x-heroicon-o-paper-clip class="h-4 w-4 shrink-0 text-ink-soft" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-medium text-ink">{{ $document->displayName() }}</span>
                                        <span class="block text-[0.875rem] text-ink-soft">{{ $document->extension() }} · {{ $document->humanSize() }}</span>
                                    </span>
                                    <x-heroicon-m-arrow-down-tray class="h-4 w-4 shrink-0 text-forest-700" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @include('public.messages.partials.order-trail', ['order' => $order])

            <p class="mt-2 text-right text-[0.875rem] text-ink-soft">{{ $message->created_at->format('g:i A') }}</p>
        </div>

        {{-- --------------------------------------------------- buyer action --}}
        {{--
            Only the buyer closes the transaction. OrderLifecycleService::
            complete() refuses the supplier outright, and OrderService refuses
            the move from anything other than `delivered`.
        --}}
        @if ($isBuyer && $order->status === \App\Enums\OrderStatus::Delivered)
            <div class="mt-2">
                <button type="button" wire:click="completeOrder({{ $order->getKey() }})"
                        class="w-full rounded-xl bg-forest-700 px-3.5 py-2.5 text-[1.0625rem] font-semibold text-white">
                    Confirm receipt and close this order
                </button>
                <p class="mt-1.5 text-center text-[0.875rem] text-ink-soft">
                    Only you can close this order. Once closed you can review the supplier.
                </p>
            </div>
        @endif
    </div>
@endif
