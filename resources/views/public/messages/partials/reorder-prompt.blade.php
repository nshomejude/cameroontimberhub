{{--
    The buyer's "order again" control, shared by every card that honestly hosts
    it (the completion card, and the order reference card once the goods have
    landed). One partial rather than two copies, so the wording and — more
    importantly — the absence of any price field cannot drift between them.

    Expects: $order, $isBuyer, $user, $conversation, $reorderForOrderId.

    It draws nothing at all unless ReorderService says this viewer may reorder
    this order. That is a courtesy: the service refuses the action regardless of
    what was drawn.
--}}
@php
    $reorders = app(\App\Services\ReorderService::class);
    $canReorder = $order && $isBuyer && $reorders->canReorder($user, $order);
    $openReorder = $canReorder ? $reorders->openReorderFor($order) : null;
@endphp

@if ($canReorder && $openReorder)
    <p class="mt-2 rounded-xl border border-sand-200 bg-white px-3.5 py-2.5 text-center text-[1.0625rem] text-ink-soft">
        A reorder request ({{ $openReorder->reference_code }}) is already open on this order.
    </p>
@elseif ($canReorder)
    <div class="mt-2 rounded-2xl border border-forest-200 bg-white p-4">
        @if ($reorderForOrderId === $order->getKey())
            <form wire:submit.prevent="submitReorder">
                <p class="font-display text-[1.125rem] font-bold text-forest-950">Order this again</p>
                {{--
                    The mockup promises "Your previous pricing and terms will be
                    applied." They will not, and this says so instead: an old
                    price is not a current offer and the platform must not imply
                    the supplier has agreed to one.
                --}}
                <p class="mt-1 text-[0.9375rem] text-ink-soft">
                    Adjust the quantities you need. Your request is reviewed before it reaches
                    {{ $conversation->company?->name }}, who then confirm current pricing and send a new
                    quotation — the previous prices do not carry over.
                </p>

                @foreach ($order->items as $item)
                    <label class="mt-3 block text-[0.9375rem] font-semibold text-ink">
                        {{ $item->description }}
                        <span class="mt-1 flex items-center gap-2">
                            <input type="number" step="0.01" min="0.01" inputmode="decimal"
                                   wire:model="reorderForm.quantities.{{ $item->getKey() }}"
                                   class="w-full rounded-xl border border-sand-300 px-3 py-2 text-[1.0625rem]">
                            <span class="shrink-0 text-[1.0625rem] text-ink-soft">{{ $item->unit }}</span>
                        </span>
                    </label>
                @endforeach

                <label class="mt-3 block text-[0.9375rem] font-semibold text-ink">
                    Delivery port (optional)
                    <input type="text" maxlength="120" wire:model="reorderForm.shipping_port"
                           class="mt-1 w-full rounded-xl border border-sand-300 px-3 py-2 text-[1.0625rem]">
                </label>

                <label class="mt-2 block text-[0.9375rem] font-semibold text-ink">
                    Needed by (optional)
                    <input type="date" wire:model="reorderForm.deadline"
                           class="mt-1 w-full rounded-xl border border-sand-300 px-3 py-2 text-[1.0625rem]">
                </label>

                <label class="mt-2 block text-[0.9375rem] font-semibold text-ink">
                    Anything different this time? (optional)
                    <textarea rows="2" maxlength="1000" wire:model="reorderForm.notes"
                              class="mt-1 w-full rounded-xl border border-sand-300 px-3 py-2 text-[1.0625rem]"></textarea>
                </label>

                @foreach ($errors->keys() as $key)
                    @if (\Illuminate\Support\Str::startsWith($key, 'reorderForm'))
                        <p class="mt-2 text-[0.9375rem] font-medium text-red-700">{{ $errors->first($key) }}</p>
                    @endif
                @endforeach

                <div class="mt-3 flex gap-2">
                    <button type="submit" class="flex-1 rounded-xl bg-forest-700 px-3.5 py-2.5 text-[1.0625rem] font-semibold text-white">
                        Send reorder request
                    </button>
                    <button type="button" wire:click="cancelReorder"
                            class="rounded-xl border border-sand-300 px-3.5 py-2.5 text-[1.0625rem] font-semibold text-ink-soft">
                        Cancel
                    </button>
                </div>
            </form>
        @else
            <div class="flex items-start gap-2.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-forest-700 text-white">
                    <x-heroicon-o-arrow-path class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="font-display text-[1.125rem] font-bold text-forest-950">Order this again</p>
                    <p class="text-[1.0625rem] text-ink-soft">
                        Repeat this order with the same supplier. Your request is reviewed first, and they
                        confirm current pricing before you accept.
                    </p>
                </div>
            </div>
            <button type="button" wire:click="openReorder({{ $order->getKey() }})"
                    class="mt-3 w-full rounded-xl border border-forest-700 px-3.5 py-2.5 text-[1.0625rem] font-semibold text-forest-700 transition hover:bg-forest-50">
                Reorder
            </button>
        @endif
    </div>
@endif
