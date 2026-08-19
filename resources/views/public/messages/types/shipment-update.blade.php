{{--
    SHIPMENT UPDATE card — covers "ORDER PROCESSING", "SHIPMENT INFORMATION"
    and "ORDER SHIPPED" from the mockups, because all three are the same card
    reading a different live status.

    ⚠ WHAT WAS DELIBERATELY NOT BUILT. Read before adding anything here.

    The mockups show a "LIVE TRACKING" panel with a map of the Gulf of Guinea, a
    dashed vessel route, a "Live" pill, a "Last updated 3:20 PM" stamp, a
    "Current Location: At Sea" readout and a "View Full Tracking History" link.
    None of it exists and none of it is rendered:

      - There is NO carrier integration. Nothing on this platform talks to
        Maersk, to a port, to AIS or to any tracking API, so there is no vessel
        position to draw and no event history to list. A map with a dashed line
        on it would be a drawing, not data.
      - "Live" and "Last updated" would be assertions about a feed that does not
        exist.
      - No map library was added (and none may be — no new packages).

    What IS shown is what a human on the supplier side actually typed:
    Order::shipmentFacts() returns only the populated fields, so an absent
    carrier renders NOTHING — not the mockup's "To be assigned" placeholder,
    which reads as a promise the platform cannot keep.

    The progress trail is honest for the same reason: it is derived entirely
    from the real timestamp columns on the order (awarded_at, confirmed_at,
    production_started_at, shipped_at, delivered_at, completed_at) and prints
    "Pending" with no date for anything that has not happened.

    Live half: everything except the reference code.
--}}
@php
    /** @var \App\Models\Order|null $order */
    $order = $message->related;

    $facts = $order?->shipmentFacts() ?? [];
    $trackingLink = $order?->trackingLink();
@endphp

@if ($order)
    <div class="py-1" id="m{{ $message->getKey() }}">
        <div class="mb-2 flex items-center gap-3">
            <span class="h-px flex-1 bg-sand-300"></span>
            <span class="text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-ink-soft">Order update</span>
            <span class="h-px flex-1 bg-sand-300"></span>
        </div>

        <div class="rounded-2xl border border-sand-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-start gap-2.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-forest-700 text-white">
                    <x-heroicon-o-truck class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="font-display text-[0.9375rem] font-bold text-forest-950">
                        Order {{ $message->payloadValue('reference_code') }}
                    </p>
                    {{-- LIVE: the enum's own literal description of what has
                         actually happened. Never a projected next step. --}}
                    <p class="text-[0.8125rem] text-ink-soft">{{ $order->status->description() }}</p>
                </div>
                <x-account.status-pill :label="$order->status->label()" :color="$order->status->color()" />
            </div>

            {{-- --------------------------------------- shipment facts, if any --}}
            @if ($facts !== [])
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2.5 border-t border-sand-200 pt-3 text-[0.8125rem]">
                    @foreach ($facts as $label => $value)
                        <div>
                            <dt class="text-[0.75rem] text-ink-soft">{{ $label }}</dt>
                            <dd class="font-medium text-ink">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif

            {{-- The carrier's own tracking page, only when the supplier gave a
                 real http(s) URL. Order::trackingLink() refuses anything else,
                 so a `javascript:` value can never become an href. It is an
                 outbound link to a third party, hence noopener + nofollow. --}}
            @if ($trackingLink)
                <a href="{{ $trackingLink }}" target="_blank" rel="noopener noreferrer nofollow"
                   class="mt-3 flex items-center justify-center gap-2 rounded-xl border border-sand-200 px-3.5 py-2.5 text-[0.875rem] font-semibold text-forest-700 transition hover:bg-sand-50">
                    <x-heroicon-m-arrow-top-right-on-square class="h-4 w-4" />
                    Track on the carrier's site
                </a>
                <p class="mt-1.5 text-center text-[0.6875rem] text-ink-soft">
                    Opens the carrier's own website. Cameroon Timber Hub does not track shipments.
                </p>
            @endif

            {{-- ------------------------------------------- live milestone trail --}}
            @include('public.messages.partials.order-trail', ['order' => $order])

            <p class="mt-2 text-right text-[0.6875rem] text-ink-soft">{{ $message->created_at->format('g:i A') }}</p>
        </div>

        {{-- ---------------------------------------------- supplier actions --}}
        {{--
            Rendered for the supplier only, and only for the moves the order can
            legally make next — OrderService::TRANSITIONS is the authority, and
            it throws for anything else even if a button were forced.
        --}}
        @if (! $isBuyer && ! $order->status->isTerminal())
            <div class="mt-2 space-y-2">
                @if ($trackingForOrderId === $order->getKey())
                    <form wire:submit.prevent="saveTracking" class="rounded-2xl border border-sand-200 bg-white p-4">
                        <p class="font-display text-[0.875rem] font-bold text-forest-950">Shipment details</p>
                        <p class="mt-1 text-[0.75rem] text-ink-soft">
                            Only what you fill in is shown to the buyer. Leave anything you do not
                            know empty — blank fields are not displayed at all.
                        </p>

                        <div class="mt-3 grid grid-cols-2 gap-2">
                            @foreach ([
                                'carrier' => ['Carrier', 'text'],
                                'tracking_number' => ['Tracking number', 'text'],
                                'shipping_method' => ['Shipping method', 'text'],
                                'vessel_name' => ['Vessel', 'text'],
                                'voyage_number' => ['Voyage', 'text'],
                                'container_number' => ['Container', 'text'],
                                'port_of_loading' => ['Port of loading', 'text'],
                                'port_of_discharge' => ['Port of discharge', 'text'],
                                'etd' => ['Departed', 'date'],
                                'eta' => ['Estimated arrival', 'date'],
                            ] as $field => [$label, $type])
                                <label class="block text-[0.75rem] font-semibold text-ink">
                                    {{ $label }}
                                    <input type="{{ $type }}" wire:model="trackingForm.{{ $field }}"
                                           class="mt-1 w-full rounded-xl border border-sand-300 px-2.5 py-1.5 text-[0.8125rem]">
                                </label>
                            @endforeach
                        </div>

                        <label class="mt-2 block text-[0.75rem] font-semibold text-ink">
                            Carrier tracking link (optional)
                            <input type="url" wire:model="trackingForm.tracking_url" placeholder="https://…"
                                   class="mt-1 w-full rounded-xl border border-sand-300 px-2.5 py-1.5 text-[0.8125rem]">
                        </label>

                        @foreach (['trackingForm.tracking_url', 'trackingForm.carrier', 'trackingForm.etd', 'trackingForm.eta'] as $field)
                            @if ($errors->has($field))
                                <p class="mt-2 text-[0.75rem] font-medium text-red-700">{{ $errors->first($field) }}</p>
                            @endif
                        @endforeach

                        <div class="mt-3 flex gap-2">
                            <button type="submit" class="flex-1 rounded-xl bg-forest-700 px-3.5 py-2.5 text-[0.875rem] font-semibold text-white">
                                Save shipment details
                            </button>
                            <button type="button" wire:click="cancelTracking" class="rounded-xl border border-sand-300 px-3.5 py-2.5 text-[0.875rem] font-semibold text-ink-soft">
                                Cancel
                            </button>
                        </div>
                    </form>
                @else
                    <div class="flex flex-wrap gap-2">
                        @if ($order->status === \App\Enums\OrderStatus::Awarded)
                            <button type="button" wire:click="confirmOrder({{ $order->getKey() }})"
                                    class="flex-1 rounded-xl bg-forest-700 px-3.5 py-2.5 text-[0.875rem] font-semibold text-white">
                                Confirm order
                            </button>
                        @endif

                        @if ($order->status === \App\Enums\OrderStatus::Confirmed)
                            <button type="button" wire:click="startProduction({{ $order->getKey() }})"
                                    class="flex-1 rounded-xl bg-forest-700 px-3.5 py-2.5 text-[0.875rem] font-semibold text-white">
                                Start production
                            </button>
                        @endif

                        @if (in_array($order->status, [\App\Enums\OrderStatus::Confirmed, \App\Enums\OrderStatus::InProduction], true))
                            <button type="button" wire:click="shipOrder({{ $order->getKey() }})"
                                    class="flex-1 rounded-xl bg-forest-700 px-3.5 py-2.5 text-[0.875rem] font-semibold text-white">
                                Mark as shipped
                            </button>
                        @endif

                        @if ($order->status === \App\Enums\OrderStatus::Shipped)
                            <button type="button" wire:click="deliverOrder({{ $order->getKey() }})"
                                    class="flex-1 rounded-xl bg-forest-700 px-3.5 py-2.5 text-[0.875rem] font-semibold text-white">
                                Mark as delivered
                            </button>
                        @endif

                        <button type="button" wire:click="openTracking({{ $order->getKey() }})"
                                class="rounded-xl border border-sand-300 bg-white px-3.5 py-2.5 text-[0.875rem] font-semibold text-forest-700">
                            Shipment details
                        </button>
                    </div>
                @endif
            </div>
        @endif
    </div>
@endif
