{{--
    One thread.

    Renders server-side on first paint, so the whole conversation is readable
    with JavaScript disabled; Livewire adds the 10s poll and in-place posting.
    The composer is a real <form action> POST, so it still works without JS.

    Deliberately absent (no data behind them): the "Online" presence dot, the
    typing indicator, the voice-note button, and the paperclip — messaging has
    no attachment storage yet, so shipping the clip would be a dead control.
--}}
<div class="flex min-h-0 flex-1 flex-col">

    {{-- ---------------------------------------------------------- header --}}
    <div class="flex items-center gap-3 border-b border-sand-200 bg-white px-4 py-3">
        @if ($backUrl)
            <a href="{{ $backUrl }}" wire:navigate class="-ml-2 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink lg:hidden" aria-label="Back to inbox">
                <x-heroicon-m-chevron-left class="h-6 w-6" />
            </a>
        @endif

        <span class="h-11 w-11 shrink-0 overflow-hidden rounded-full bg-sand-200">
            @if ($logo = $conversation->counterpartyLogoUrl($user))
                <img src="{{ $logo }}" alt="" class="h-full w-full object-cover">
            @else
                <span class="flex h-full w-full items-center justify-center text-[0.8125rem] font-bold text-forest-800">
                    {{ $conversation->counterpartyInitials($user) }}
                </span>
            @endif
        </span>

        <div class="min-w-0 flex-1">
            <p class="flex items-center gap-1.5 truncate font-display text-[1rem] font-bold text-forest-950">
                {{ $conversation->counterpartyName($user) }}
                @if ($conversation->counterpartyIsVerified($user))
                    <x-heroicon-s-check-badge class="h-4 w-4 shrink-0 text-forest-600" />
                @endif
            </p>
            <p class="truncate text-[0.8125rem] text-ink-soft">
                {{ $conversation->subjectLine() }}
                @if ($conversation->counterpartyIsVerified($user)) · Verified supplier @endif
            </p>
        </div>

        {{-- The existing WhatsApp/phone affordance is kept, not replaced:
             in-platform chat is an addition, so a supplier who publishes a
             number is still one tap away. --}}
        @if ($chat = $conversation->company?->chatLink())
            <a href="{{ $chat['url'] }}" rel="noopener nofollow"
               class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-forest-700 transition hover:bg-forest-50"
               aria-label="{{ $chat['label'] }}">
                <x-heroicon-o-phone class="h-5 w-5" />
            </a>
        @endif
    </div>

    {{-- --------------------------------------------------------- context --}}
    @if ($conversation->product)
        <div class="border-b border-sand-200 bg-sand-100 px-4 py-3">
            <div class="rounded-2xl border border-sand-200 bg-white p-3">
                <div class="flex items-center gap-3">
                    @if ($img = $conversation->product->primaryImageUrl())
                        <img src="{{ $img }}" alt="" class="h-14 w-20 shrink-0 rounded-xl object-cover">
                    @endif
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-display text-[0.9375rem] font-bold text-forest-950">{{ $conversation->product->name }}</p>
                        <p class="truncate text-[0.8125rem] text-ink-soft">
                            {{ $conversation->company?->name }}@if ($conversation->company?->region) · {{ $conversation->company->region }}@endif
                        </p>
                    </div>
                    <a href="{{ route('products.show', $conversation->product->slug) }}"
                       class="shrink-0 rounded-xl border border-forest-700 px-3 py-2 text-[0.8125rem] font-bold text-forest-700 transition hover:bg-forest-50">
                        View product
                    </a>
                </div>
            </div>
        </div>
    @endif

    {{-- -------------------------------------------------------- messages --}}
    <div class="flex-1 space-y-3 overflow-y-auto bg-sand-100 px-4 py-4" wire:poll.10s>
        @php $lastDay = null; @endphp

        @forelse ($messages as $message)
            @php $day = $message->created_at->toDateString(); @endphp

            @if ($day !== $lastDay)
                @php $lastDay = $day; @endphp
                <div class="flex justify-center">
                    <span class="rounded-full bg-white px-3 py-1 text-[0.75rem] font-semibold text-ink-soft shadow-sm">
                        @if ($message->created_at->isToday()) Today
                        @elseif ($message->created_at->isYesterday()) Yesterday
                        @else {{ $message->created_at->isoFormat('D MMMM YYYY') }}
                        @endif
                    </span>
                </div>
            @endif

            @include($message->type->partial(), [
                'message' => $message,
                'user' => $user,
                'conversation' => $conversation,
                'messaging' => $messaging,
                // Resolved once in the component. No card recomputes which
                // side of the thread the viewer is on.
                'isBuyer' => $isBuyer,
                'counteringQuoteId' => $counteringQuoteId,
                // Phase 3: which lifecycle form (if any) is open, so a card can
                // render its own inline editor without owning the state.
                'trackingForOrderId' => $trackingForOrderId,
                'paymentForOrderId' => $paymentForOrderId,
                'reviewForOrderId' => $reviewForOrderId,
            ])
        @empty
            <p class="py-8 text-center text-[0.875rem] text-ink-soft">No messages yet. Say hello.</p>
        @endforelse
    </div>

    {{-- ---------------------------------------------------- RFQ composer --}}
    {{--
        Mockup: "REQUEST FOR QUOTE (RFQ)" sitting inline in the thread, opened
        from the "+" beside the composer.

        Buyer only, and only structurally: the supplier never sees the trigger,
        and ChatCommerceService::assertBuyer() refuses the action regardless.

        The fields are the ones the RFQ schema actually has. There is no
        "Attach Files" row from the mockup because messaging still has no
        attachment storage — shipping the buttons would be three dead controls.
    --}}
    @if ($isBuyer)
        <div class="border-t border-sand-200 bg-sand-100 px-4 py-3">
            @if ($showRfqForm)
                <form wire:submit.prevent="submitRfq" class="rounded-2xl border border-sand-200 bg-white p-4">
                    <div class="flex items-start gap-2.5">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-forest-700 text-white">
                            <x-heroicon-o-document-text class="h-5 w-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-display text-[0.9375rem] font-bold text-forest-950">Request for quote</p>
                            <p class="text-[0.75rem] text-ink-soft">Fill in the details below to request a quotation.</p>
                        </div>
                        <button type="button" wire:click="toggleRfqForm" class="shrink-0 text-ink-soft hover:text-ink" aria-label="Close request form">
                            <x-heroicon-m-x-mark class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3 text-[0.8125rem]">
                        <div class="col-span-2">
                            <label for="rfq-species" class="block text-[0.75rem] font-semibold text-ink-soft">Species / product <span class="text-red-600">*</span></label>
                            <input id="rfq-species" type="text" maxlength="180" wire:model="rfqForm.species_text"
                                   class="mt-1 w-full rounded-xl border border-sand-300 bg-sand-50 px-3 py-2 outline-none focus:border-forest-500">
                        </div>

                        <div>
                            <label for="rfq-quantity" class="block text-[0.75rem] font-semibold text-ink-soft">Quantity <span class="text-red-600">*</span></label>
                            <input id="rfq-quantity" type="number" step="0.01" min="0.01" wire:model="rfqForm.quantity"
                                   class="mt-1 w-full rounded-xl border border-sand-300 bg-sand-50 px-3 py-2 outline-none focus:border-forest-500">
                        </div>
                        <div>
                            <label for="rfq-unit" class="block text-[0.75rem] font-semibold text-ink-soft">Unit <span class="text-red-600">*</span></label>
                            <select id="rfq-unit" wire:model="rfqForm.unit"
                                    class="mt-1 w-full rounded-xl border border-sand-300 bg-sand-50 px-3 py-2 outline-none focus:border-forest-500">
                                @foreach ($unitOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="rfq-form" class="block text-[0.75rem] font-semibold text-ink-soft">Form</label>
                            <select id="rfq-form" wire:model="rfqForm.form"
                                    class="mt-1 w-full rounded-xl border border-sand-300 bg-sand-50 px-3 py-2 outline-none focus:border-forest-500">
                                <option value="">—</option>
                                @foreach ($formOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="rfq-grade" class="block text-[0.75rem] font-semibold text-ink-soft">Grade</label>
                            <input id="rfq-grade" type="text" maxlength="60" wire:model="rfqForm.grade"
                                   class="mt-1 w-full rounded-xl border border-sand-300 bg-sand-50 px-3 py-2 outline-none focus:border-forest-500">
                        </div>

                        <div class="col-span-2">
                            <label for="rfq-dimensions" class="block text-[0.75rem] font-semibold text-ink-soft">Dimensions</label>
                            <input id="rfq-dimensions" type="text" maxlength="255" wire:model="rfqForm.dimensions"
                                   class="mt-1 w-full rounded-xl border border-sand-300 bg-sand-50 px-3 py-2 outline-none focus:border-forest-500">
                        </div>

                        <div>
                            <label for="rfq-moisture" class="block text-[0.75rem] font-semibold text-ink-soft">Moisture</label>
                            <input id="rfq-moisture" type="text" maxlength="60" wire:model="rfqForm.moisture_content"
                                   class="mt-1 w-full rounded-xl border border-sand-300 bg-sand-50 px-3 py-2 outline-none focus:border-forest-500">
                        </div>
                        <div>
                            <label for="rfq-incoterm" class="block text-[0.75rem] font-semibold text-ink-soft">Delivery terms</label>
                            <select id="rfq-incoterm" wire:model="rfqForm.incoterm"
                                    class="mt-1 w-full rounded-xl border border-sand-300 bg-sand-50 px-3 py-2 outline-none focus:border-forest-500">
                                <option value="">—</option>
                                @foreach ($incotermOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="rfq-port" class="block text-[0.75rem] font-semibold text-ink-soft">Destination port</label>
                            <input id="rfq-port" type="text" maxlength="120" wire:model="rfqForm.shipping_port"
                                   class="mt-1 w-full rounded-xl border border-sand-300 bg-sand-50 px-3 py-2 outline-none focus:border-forest-500">
                        </div>
                        <div>
                            <label for="rfq-deadline" class="block text-[0.75rem] font-semibold text-ink-soft">Required by</label>
                            <input id="rfq-deadline" type="date" wire:model="rfqForm.deadline"
                                   class="mt-1 w-full rounded-xl border border-sand-300 bg-sand-50 px-3 py-2 outline-none focus:border-forest-500">
                        </div>

                        <div class="col-span-2">
                            <label for="rfq-notes" class="block text-[0.75rem] font-semibold text-ink-soft">Additional requirements</label>
                            <textarea id="rfq-notes" rows="2" maxlength="500" wire:model="rfqForm.notes"
                                      placeholder="Eg. surface finish, certification, packaging."
                                      class="mt-1 w-full resize-y rounded-xl border border-sand-300 bg-sand-50 px-3 py-2 outline-none focus:border-forest-500"></textarea>
                        </div>
                    </div>

                    @foreach (['rfqForm.species_text', 'rfqForm.quantity', 'rfqForm.unit', 'rfqForm.deadline'] as $field)
                        @if ($fieldError = $errors->first($field))
                            <p class="mt-2 text-[0.8125rem] font-medium text-red-700">{{ $fieldError }}</p>
                        @endif
                    @endforeach

                    <button type="submit"
                            class="mt-3 w-full rounded-xl bg-forest-800 px-4 py-2.5 text-[0.875rem] font-bold text-white transition hover:bg-forest-900">
                        Send request for quote
                    </button>
                </form>
            @else
                <button type="button" wire:click="toggleRfqForm"
                        class="flex w-full items-center justify-center gap-2 rounded-xl border border-forest-700 bg-white px-4 py-2.5 text-[0.875rem] font-bold text-forest-700 transition hover:bg-forest-50">
                    <x-heroicon-m-plus class="h-4 w-4" />
                    Post a requirement (RFQ)
                </button>
            @endif
        </div>
    @endif

    {{-- -------------------------------------------------------- composer --}}
    <div class="border-t border-sand-200 bg-white px-4 py-3">
        @if (session('chat_status'))
            <p class="mb-2 rounded-xl bg-forest-50 px-3 py-2 text-[0.8125rem] font-medium text-forest-800">{{ session('chat_status') }}</p>
        @endif
        @if (session('chat_error'))
            <p class="mb-2 rounded-xl bg-red-50 px-3 py-2 text-[0.8125rem] font-medium text-red-700">{{ session('chat_error') }}</p>
        @endif

        @if ($replyTo)
            <div class="mb-2 flex items-center gap-2 rounded-xl border-l-2 border-forest-500 bg-sand-100 px-3 py-2">
                <span class="min-w-0 flex-1 truncate text-[0.8125rem] text-ink-soft">Replying to: {{ $replyTo->preview(60) }}</span>
                <button type="button" wire:click="cancelReply" class="shrink-0 text-ink-soft hover:text-ink" aria-label="Cancel reply">
                    <x-heroicon-m-x-mark class="h-4 w-4" />
                </button>
            </div>
        @endif

        {{-- Real form action so the composer degrades to a plain POST when
             Livewire is not running. --}}
        <form method="POST"
              @if ($postAction) action="{{ $postAction }}" @endif
              wire:submit.prevent="send"
              class="flex items-end gap-2">
            @csrf
            <label for="composer-{{ $conversation->getKey() }}" class="sr-only">Message</label>
            <textarea id="composer-{{ $conversation->getKey() }}" name="body" rows="1"
                      wire:model="body"
                      placeholder="Type a message…"
                      class="min-h-11 max-h-40 min-w-0 flex-1 resize-y rounded-2xl border border-sand-300 bg-sand-50 px-4 py-2.5 text-[0.9375rem] text-ink outline-none focus:border-forest-500"></textarea>
            <button type="submit"
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-forest-800 text-white transition hover:bg-forest-900"
                    aria-label="Send message">
                <x-heroicon-m-paper-airplane class="h-5 w-5" />
            </button>
        </form>

        @error('body')
            <p class="mt-2 text-[0.8125rem] font-medium text-red-700">{{ $message }}</p>
        @enderror
    </div>
</div>
