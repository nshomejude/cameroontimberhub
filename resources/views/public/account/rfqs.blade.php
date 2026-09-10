<x-layouts.account
    :title="__('messages.account.rfqs_title')"
    :heading="__('messages.account.rfqs_title')"
    :subheading="__('messages.account.rfqs_subheading')">

    @if ($rfqs->total() === 0)
        <x-account.blank icon="document-text"
            :title="__('messages.account.rfqs_empty_title')"
            :body="__('messages.account.rfqs_empty_body')"
            :cta-label="__('messages.account.post_an_rfq')" :cta-url="route('rfq.create')" />
    @else
        <p class="mb-3 text-[1.0625rem] text-ink-soft">
            {{ trans_choice('messages.account.rfqs_count', $rfqs->total(), ['count' => number_format($rfqs->total())]) }}
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
                                    <x-account.status-pill :label="__('messages.account.email_not_confirmed')" color="warning" />
                                @endunless
                            </div>
                            @if ($rfq->title)
                                <p class="mt-1 truncate text-[1.125rem] font-medium text-ink">{{ $rfq->title }}</p>
                            @endif
                            <p class="mt-1 text-[1.0625rem] text-ink-soft">
                                {{ $rfq->items->take(3)->map(fn ($item) => $item->label())->implode(' · ') ?: __('messages.account.no_line_items') }}@if ($rfq->items->count() > 3) …@endif
                            </p>
                            <p class="mt-1 text-[0.9375rem] text-ink-soft">
                                {{ __('messages.account.posted_on', ['date' => $rfq->created_at?->isoFormat('D MMM YYYY')]) }}
                                @if ($rfq->deadline) · {{ __('messages.account.needed_by', ['date' => $rfq->deadline->isoFormat('D MMM YYYY')]) }} @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-col items-end gap-2">
                            <span class="text-[1.0625rem] text-ink-soft">
                                <strong class="font-display text-xl font-bold text-forest-800">{{ $rfq->quotes_count }}</strong>
                                {{ trans_choice('messages.account.quote_word', $rfq->quotes_count) }}
                            </span>
                            <a href="{{ $access->link(request(), 'responses', $rfq) }}"
                               class="inline-flex items-center gap-1.5 rounded-full bg-forest-700 px-4 py-2 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800">
                                {{ $rfq->quotes_count > 0 ? __('messages.account.compare_quotes') : __('messages.account.view_request') }}
                                <x-heroicon-m-arrow-right class="h-4 w-4" />
                            </a>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-5"><x-account.pagination :paginator="$rfqs" :noun="__('messages.account.noun_requests')" /></div>
    @endif
</x-layouts.account>
