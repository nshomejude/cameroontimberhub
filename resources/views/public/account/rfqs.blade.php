<x-layouts.account
    title="RFQ Center"
    heading="RFQ Center"
    subheading="Every request for quotation on this account.">

    @if ($rfqs->total() === 0)
        <x-account.blank icon="document-text"
            title="You have not posted a request yet"
            body="Tell us the species, form, volume and destination you need, and we route it to verified Cameroonian suppliers."
            cta-label="Post an RFQ" :cta-url="route('rfq.create')" />
    @else
        <p class="mb-3 text-[0.8125rem] text-ink-soft">
            {{ number_format($rfqs->total()) }} {{ Str::plural('request', $rfqs->total()) }}
        </p>

        <ul class="space-y-3">
            @foreach ($rfqs as $rfq)
                <li class="rounded-2xl border border-sand-200 bg-white p-4 lg:p-5">
                    <div class="flex flex-wrap items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-display text-[1.0625rem] font-bold text-forest-950">{{ $rfq->reference_code }}</span>
                                <x-account.status-pill :label="$rfq->status->label()" :color="$rfq->status->color()" />
                                @unless ($rfq->isVerified())
                                    <x-account.status-pill label="Email not confirmed" color="warning" />
                                @endunless
                            </div>
                            @if ($rfq->title)
                                <p class="mt-1 truncate text-[0.9375rem] font-medium text-ink">{{ $rfq->title }}</p>
                            @endif
                            <p class="mt-1 text-[0.8125rem] text-ink-soft">
                                {{ $rfq->items->take(3)->map(fn ($item) => $item->label())->implode(' · ') ?: 'No line items' }}@if ($rfq->items->count() > 3) …@endif
                            </p>
                            <p class="mt-1 text-[0.75rem] text-ink-soft">
                                Posted {{ $rfq->created_at?->isoFormat('D MMM YYYY') }}
                                @if ($rfq->deadline) · Needed by {{ $rfq->deadline->isoFormat('D MMM YYYY') }} @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-col items-end gap-2">
                            <span class="text-[0.8125rem] text-ink-soft">
                                <strong class="font-display text-xl font-bold text-forest-800">{{ $rfq->quotes_count }}</strong>
                                {{ Str::plural('quote', $rfq->quotes_count) }}
                            </span>
                            <a href="{{ $access->link(request(), 'responses', $rfq) }}"
                               class="inline-flex items-center gap-1.5 rounded-full bg-forest-700 px-4 py-2 text-[0.8125rem] font-semibold text-white transition hover:bg-forest-800">
                                {{ $rfq->quotes_count > 0 ? 'Compare quotes' : 'View request' }}
                                <x-heroicon-m-arrow-right class="h-4 w-4" />
                            </a>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-5"><x-account.pagination :paginator="$rfqs" noun="requests" /></div>
    @endif
</x-layouts.account>
