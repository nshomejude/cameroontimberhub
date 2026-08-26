<x-layouts.account
    title="Receipts"
    heading="Receipts"
    subheading="Platform-issued records of your awarded orders.">

    {{--
        Receipt tokens never appear here. The verification token is a bearer
        secret; it is hidden on the model and the only place it is ever emitted
        is the printed receipt's own QR link. This page links to the receipt
        document, not to /verify/{token}.
    --}}
    @if ($receipts->total() === 0)
        <x-account.blank icon="receipt-percent"
            title="No receipts yet"
            body="A receipt is issued automatically when you award a quote. It confirms the platform recorded the order, for the amount stated — it is not a proof of payment."
            cta-label="Review your quotes" :cta-url="route('account.quotes')" />
    @else
        <p class="mb-3 text-[1.0625rem] text-ink-soft">
            {{ number_format($receipts->total()) }} {{ Str::plural('receipt', $receipts->total()) }}
        </p>

        <ul class="space-y-3">
            @foreach ($receipts as $receipt)
                <li class="rounded-2xl border border-sand-200 bg-white p-4 lg:p-5">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-forest-50 text-forest-700">
                            <x-heroicon-o-receipt-percent class="h-5 w-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-display text-[1.0625rem] font-bold text-forest-950">{{ $receipt->receipt_number }}</p>
                            <p class="truncate text-[1.0625rem] text-ink-soft">
                                {{ $receipt->order?->reference_code }} · {{ $receipt->order?->supplier_name }}
                                · issued {{ $receipt->issued_at?->isoFormat('D MMM YYYY') }}
                            </p>
                        </div>
                        <span class="shrink-0 font-display text-lg font-bold text-forest-800">{{ $receipt->money() }}</span>
                        @if ($receipt->order?->rfq)
                            <a href="{{ $access->link(request(), 'receipt', $receipt->order->rfq) }}"
                               class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-sand-300 px-4 py-2 text-[1.0625rem] font-semibold text-ink transition hover:border-forest-400">
                                View / print <x-heroicon-m-arrow-right class="h-4 w-4" />
                            </a>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-5"><x-account.pagination :paginator="$receipts" noun="receipts" /></div>

        <p class="mt-4 rounded-2xl border border-sand-200 bg-white px-5 py-4 text-[1.0625rem] leading-relaxed text-ink-soft">
            A receipt attests that this platform issued a record for this order, for this amount, on this date.
            It is not a proof of payment — the platform settles no money. Third parties can check any receipt at
            <a href="{{ route('receipts.verify') }}" class="font-semibold text-forest-700 hover:underline">{{ route('receipts.verify') }}</a>.
        </p>
    @endif
</x-layouts.account>
