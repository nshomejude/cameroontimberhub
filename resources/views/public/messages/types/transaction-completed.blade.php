{{--
    TRANSACTION COMPLETED card + the review prompt.

    Money is snapshotted (the total as it stood when the buyer closed the
    order); everything else is live — the item count, the delivery date, the
    document count, and crucially whether a review already exists, which is what
    decides between showing the prompt and showing "you have reviewed this".

    From the mockup, deliberately NOT built:
      - "NEED SUPPORT? / Contact Support" — there is no support desk behind it.
      - "Ask for Quotation / Browse Products / View Suppliers" next-steps row.
        Generic navigation dressed as transaction follow-up. Reorder — the one
        item in that row with real meaning here — is built, in Phase 4, as the
        shared reorder prompt below.
      - The pre-filled 5/5 star row in one comp. Stars are an INPUT here and
        start empty; showing five filled stars before the buyer has chosen is
        both a fabricated rating and a nudge.

    The review prompt appears only for the buyer, only on a completed order, and
    only when no review exists yet — the same three conditions
    CompanyReviewService::canReview() enforces server-side.
--}}
@php
    /** @var \App\Models\Order|null $order */
    $order = $message->related;

    $currency = $message->payloadValue('currency');
    $existingReview = $order?->review;
    $canReview = $order && $isBuyer && app(\App\Services\CompanyReviewService::class)->canReview($user, $order);
@endphp

@if ($order)
    <div class="py-1" id="m{{ $message->getKey() }}">
        <div class="mb-2 flex items-center gap-3">
            <span class="h-px flex-1 bg-sand-300"></span>
            <span class="text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-ink-soft">Transaction completed</span>
            <span class="h-px flex-1 bg-sand-300"></span>
        </div>

        <div class="rounded-2xl border border-forest-200 bg-forest-50/60 p-4 shadow-sm">
            <div class="flex flex-wrap items-start gap-2.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-forest-700 text-white">
                    <x-heroicon-m-check class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="font-display text-[0.9375rem] font-bold text-forest-950">
                        Order {{ $message->payloadValue('reference_code') }} is closed
                    </p>
                    <p class="text-[0.8125rem] text-ink-soft">
                        @if ($iso = $message->payloadValue('completed_at'))
                            Completed {{ \Illuminate\Support\Carbon::parse($iso)->isoFormat('D MMM YYYY') }}
                        @endif
                    </p>
                </div>
            </div>

            {{-- Order summary. Each figure is either the snapshot or a live
                 count of real rows; nothing here is a placeholder. --}}
            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-forest-200 pt-3 text-[0.8125rem] sm:grid-cols-4">
                <div>
                    <dt class="text-[0.75rem] text-ink-soft">Items</dt>
                    <dd class="font-semibold text-ink">{{ $order->items->count() }}</dd>
                </div>
                <div>
                    <dt class="text-[0.75rem] text-ink-soft">Order total</dt>
                    <dd class="font-semibold text-ink">{{ $currency }} {{ number_format((float) $message->payloadValue('total_amount', 0), 2) }}</dd>
                </div>
                <div>
                    <dt class="text-[0.75rem] text-ink-soft">Delivered</dt>
                    <dd class="font-semibold text-ink">{{ $order->delivered_at?->isoFormat('D MMM YYYY') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[0.75rem] text-ink-soft">Documents</dt>
                    <dd class="font-semibold text-ink">{{ $order->documents->count() }}</dd>
                </div>
            </dl>

            @include('public.messages.partials.order-trail', ['order' => $order])

            <p class="mt-2 text-right text-[0.6875rem] text-ink-soft">{{ $message->created_at->format('g:i A') }}</p>
        </div>

        {{-- ------------------------------------------------- review prompt --}}
        @if ($existingReview)
            <p class="mt-2 rounded-xl border border-sand-200 bg-white px-3.5 py-2.5 text-center text-[0.8125rem] text-ink-soft">
                You reviewed this supplier on {{ $existingReview->created_at->isoFormat('D MMM YYYY') }}.
            </p>
        @elseif ($canReview)
            <div class="mt-2">
                @if ($reviewForOrderId === $order->getKey())
                    <form wire:submit.prevent="submitReview" class="rounded-2xl border border-amber-200 bg-amber-50/60 p-4">
                        <p class="font-display text-[0.9375rem] font-bold text-forest-950">How was your experience?</p>
                        <p class="mt-1 text-[0.75rem] text-ink-soft">
                            Your review is published on {{ $conversation->company?->name }}'s profile under your name and
                            counts towards their rating.
                        </p>

                        {{-- Stars are radio inputs, unset until the buyer picks
                             one. Nothing is pre-selected. --}}
                        <fieldset class="mt-3">
                            <legend class="text-[0.75rem] font-semibold text-ink">Rating</legend>
                            <div class="mt-1 flex gap-1.5">
                                @foreach (range(1, 5) as $star)
                                    <label class="cursor-pointer">
                                        <input type="radio" wire:model="reviewForm.rating" value="{{ $star }}" class="peer sr-only">
                                        <span class="flex h-9 w-9 items-center justify-center rounded-full border border-sand-300 bg-white text-[0.8125rem] font-bold text-ink-soft peer-checked:border-forest-700 peer-checked:bg-forest-700 peer-checked:text-white">
                                            {{ $star }}
                                        </span>
                                        <span class="sr-only">{{ $star }} out of 5</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        <label class="mt-3 block text-[0.75rem] font-semibold text-ink">
                            Headline (optional)
                            <input type="text" maxlength="160" wire:model="reviewForm.title"
                                   class="mt-1 w-full rounded-xl border border-sand-300 px-3 py-2 text-[0.875rem]">
                        </label>

                        <label class="mt-2 block text-[0.75rem] font-semibold text-ink">
                            Your review (optional)
                            <textarea rows="3" maxlength="2000" wire:model="reviewForm.body"
                                      class="mt-1 w-full rounded-xl border border-sand-300 px-3 py-2 text-[0.875rem]"></textarea>
                        </label>

                        @foreach (['reviewForm.rating', 'reviewForm.title', 'reviewForm.body'] as $field)
                            @if ($errors->has($field))
                                <p class="mt-2 text-[0.75rem] font-medium text-red-700">{{ $errors->first($field) }}</p>
                            @endif
                        @endforeach

                        <div class="mt-3 flex gap-2">
                            <button type="submit" class="flex-1 rounded-xl bg-forest-700 px-3.5 py-2.5 text-[0.875rem] font-semibold text-white">
                                Publish review
                            </button>
                            <button type="button" wire:click="cancelReview" class="rounded-xl border border-sand-300 px-3.5 py-2.5 text-[0.875rem] font-semibold text-ink-soft">
                                Cancel
                            </button>
                        </div>
                    </form>
                @else
                    <div class="rounded-2xl border border-amber-200 bg-amber-50/60 p-4">
                        <div class="flex items-start gap-2.5">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-500 text-white">
                                <x-heroicon-o-star class="h-5 w-5" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="font-display text-[0.9375rem] font-bold text-forest-950">How was your experience?</p>
                                <p class="text-[0.8125rem] text-ink-soft">
                                    Your feedback helps other buyers judge this supplier.
                                </p>
                            </div>
                        </div>
                        <button type="button" wire:click="openReview({{ $order->getKey() }})"
                                class="mt-3 w-full rounded-xl bg-forest-700 px-3.5 py-2.5 text-[0.875rem] font-semibold text-white">
                            Write a review
                        </button>
                    </div>
                @endif
            </div>
        @endif

        {{-- ------------------------------------------------ reorder prompt --}}
        {{--
            "Order again", the other half of the post-transaction surface the
            mockup shows beside "rate your experience".

            What it does NOT do is place an order. It opens a REQUEST that the
            supplier has to price before there is anything to accept.
        --}}
        @include("public.messages.partials.reorder-prompt", [
            "order" => $order,
            "isBuyer" => $isBuyer,
            "user" => $user,
            "conversation" => $conversation,
            "reorderForOrderId" => $reorderForOrderId,
        ])
    </div>
@endif
