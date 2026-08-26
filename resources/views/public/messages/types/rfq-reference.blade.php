{{--
    REQUEST FOR QUOTE card (mockup: "Direct Message actions, and Post
    Requirement").

    The mockup renders the RFQ as a live form sitting in the thread. That form
    is the composer (see thread.blade.php); what lands in the transcript
    afterwards is this read-only card, because a submitted request must not stay
    editable in the place both parties are using as the record of what was
    asked.

    Payload = the request as sent. The triage STATUS is read live off the
    related Rfq, so a request that is still awaiting review says so, and says so
    on both sides.

    Everything below is echoed with {{ }}. Species, grade, dimensions and notes
    are buyer-typed free text and are never rendered as HTML.
--}}
@php
    $rfq = $message->related;
    $items = (array) $message->payloadValue('items', []);
@endphp

<div class="py-1">
    <div class="mb-2 flex items-center gap-3">
        <span class="h-px flex-1 bg-sand-300"></span>
        <span class="text-[0.875rem] font-bold uppercase tracking-[0.14em] text-ink-soft">Request for quote</span>
        <span class="h-px flex-1 bg-sand-300"></span>
    </div>

    <div class="rounded-2xl border border-sand-200 bg-white p-4 shadow-sm">
        <div class="flex flex-wrap items-start gap-2.5">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-forest-700 text-white">
                <x-heroicon-o-document-text class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="font-display text-[1rem] font-bold text-forest-950">
                    {{ $message->payloadValue('title') ?: 'Request for quote' }}
                </p>
                <p class="text-[0.9375rem] font-semibold tracking-wide text-ink-soft">
                    {{ $message->payloadValue('reference_code') }}
                </p>
            </div>
            @if ($rfq)
                <x-account.status-pill :label="$rfq->status->label()" :color="$rfq->status->color()" />
            @endif
        </div>

        @foreach ($items as $item)
            <div class="mt-3 rounded-xl bg-sand-50 p-3 text-[1.0625rem]">
                <p class="font-semibold text-ink">
                    {{ $item['species'] ?? 'Timber' }}
                    @if (! empty($item['quantity']))
                        <span class="font-normal text-ink-soft">— {{ $item['quantity'] }} {{ $item['unit'] }}</span>
                    @endif
                </p>
                @php
                    $spec = array_filter([
                        $item['form'] ?? null,
                        ! empty($item['grade']) ? 'Grade: '.$item['grade'] : null,
                        $item['dimensions'] ?? null,
                        $item['moisture_content'] ?? null,
                    ]);
                @endphp
                @if ($spec)
                    <p class="mt-1 text-ink-soft">{{ implode(' · ', $spec) }}</p>
                @endif
            </div>
        @endforeach

        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-sand-200 pt-3 text-[1.0625rem]">
            @if ($message->payloadValue('incoterm'))
                <div>
                    <dt class="text-ink-soft">Delivery terms</dt>
                    <dd class="font-medium text-ink">{{ $message->payloadValue('incoterm') }}</dd>
                </div>
            @endif
            @if ($message->payloadValue('shipping_port'))
                <div>
                    <dt class="text-ink-soft">Destination port</dt>
                    <dd class="font-medium text-ink">{{ $message->payloadValue('shipping_port') }}</dd>
                </div>
            @endif
            @if ($message->payloadValue('deadline'))
                <div>
                    <dt class="text-ink-soft">Required by</dt>
                    <dd class="font-medium text-ink">
                        {{ \Illuminate\Support\Carbon::parse($message->payloadValue('deadline'))->isoFormat('D MMM YYYY') }}
                    </dd>
                </div>
            @endif
        </dl>

        @if ($notes = $message->payloadValue('notes'))
            <p class="mt-3 whitespace-pre-line break-words rounded-xl bg-sand-50 p-3 text-[1.0625rem] text-ink">{{ $notes }}</p>
        @endif

        {{-- Said plainly rather than implied: a request from a conversation
             still goes through the same review as one from the public form, so
             the supplier is not left wondering why they cannot quote yet. --}}
        @if ($rfq && ! $rfq->isVerified())
            <p class="mt-3 text-[0.9375rem] text-ink-soft">Awaiting email confirmation from the buyer.</p>
        @elseif ($rfq && $rfq->status === \App\Enums\RfqStatus::New)
            <p class="mt-3 text-[0.9375rem] text-ink-soft">Submitted for review. Quoting opens once it is approved.</p>
        @endif

        <p class="mt-2 text-right text-[0.875rem] text-ink-soft">{{ $message->created_at->format('g:i A') }}</p>
    </div>
</div>
