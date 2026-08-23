{{--
    RECORDED ACCEPTANCE card.

    ⚠ Read this before changing any wording here.

    This card is NOT an electronic signature and must never be dressed up as
    one. There is no certificate, no key, no signing authority, no trusted
    timestamp and no identity verification beyond "this platform account was
    authenticated at the time". Language such as "signed", "e-signature",
    "digitally signed", "legally binding", "certified", "notarised" or
    "enforceable" is therefore wrong on the facts and does not belong here.

    What it says, and all it says, is what we actually observed and stored:
    who accepted, when, from which IP, and a fingerprint of the exact figures
    they were shown at the moment they accepted. That is a useful audit record
    on its own terms; it just is not a signature.
--}}
@php
    /** @var \App\Models\ContractAcceptance|null $acceptance */
    $acceptance = $message->related;

    $currency = $message->payloadValue('currency');
    $total = $message->payloadValue('total_amount');
@endphp

<div class="py-1" id="m{{ $message->getKey() }}">
    <div class="mb-2 flex items-center gap-3">
        <span class="h-px flex-1 bg-sand-300"></span>
        <span class="text-[0.875rem] font-bold uppercase tracking-[0.14em] text-ink-soft">Acceptance recorded</span>
        <span class="h-px flex-1 bg-sand-300"></span>
    </div>

    <div class="rounded-2xl border border-forest-200 bg-forest-50/60 p-4 shadow-sm">
        <div class="flex items-start gap-2.5">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-forest-700 text-white">
                <x-heroicon-o-check-circle class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1">
                {{-- Plain statement of fact, in the past tense, naming the
                     actor and the moment. Nothing is asserted beyond it. --}}
                <p class="font-display text-[1.125rem] font-bold text-forest-950">
                    Accepted by {{ $message->payloadValue('accepted_by_name') }}
                </p>
                <p class="text-[1.0625rem] text-ink-soft">
                    @if ($iso = $message->payloadValue('accepted_at'))
                        on {{ \Illuminate\Support\Carbon::parse($iso)->isoFormat('D MMMM YYYY [at] HH:mm') }} (UTC{{ \Illuminate\Support\Carbon::parse($iso)->format('P') }})
                    @endif
                </p>
            </div>
        </div>

        <dl class="mt-3 space-y-2 border-t border-forest-200 pt-3 text-[1.0625rem]">
            <div class="flex items-start justify-between gap-4">
                <dt class="text-ink-soft">Quotation</dt>
                <dd class="font-semibold text-ink">{{ $message->payloadValue('quote_reference') }}</dd>
            </div>
            @if ($total !== null)
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-ink-soft">Accepted total</dt>
                    <dd class="font-semibold text-ink">{{ $currency }} {{ number_format((float) $total, 2) }}</dd>
                </div>
            @endif
            @if ($ip = $message->payloadValue('ip_address'))
                <div class="flex items-start justify-between gap-4">
                    <dt class="text-ink-soft">Recorded from</dt>
                    <dd class="font-medium text-ink">{{ $ip }}</dd>
                </div>
            @endif
            <div class="flex items-start justify-between gap-4">
                <dt class="shrink-0 text-ink-soft">Terms fingerprint</dt>
                <dd class="break-all text-right font-mono text-[0.9375rem] text-ink">
                    {{ $acceptance?->shortHash() ?? substr((string) $message->payloadValue('terms_hash'), 0, 16) }}…
                </dd>
            </div>
        </dl>

        {{-- The disclaimer is part of the record, not fine print bolted on: it
             is the sentence that keeps the rest of the card true. --}}
        <p class="mt-3 rounded-xl bg-white/70 p-3 text-[0.9375rem] leading-relaxed text-ink-soft">
            This is a record kept by Cameroon Timber Hub of an acceptance made through this
            conversation. It stores the account that accepted, the time, the network address
            the request came from, and a SHA-256 fingerprint of the exact quotation figures
            shown at that moment, so the record can be checked against them later.
            It is not an electronic signature and makes no statement about the legal
            standing of any agreement between the parties.
        </p>

        <p class="mt-2 text-right text-[0.875rem] text-ink-soft">{{ $message->created_at->format('g:i A') }}</p>
    </div>
</div>
