<x-layouts.account
    :title="__('messages.account_center.disputes_title')"
    :heading="__('messages.account_center.disputes_title')"
    :subheading="__('messages.account_center.disputes_subheading')">

    @if ($disputes->total() === 0)
        <x-account.blank icon="exclamation-triangle"
            :title="__('messages.account_center.disputes_empty_title')"
            :body="__('messages.account_center.disputes_empty_body')"
            :cta-label="__('messages.account.nav_orders')" :cta-url="route('account.orders')" />
    @else
        <ul class="space-y-3">
            @foreach ($disputes as $dispute)
                <li class="rounded-2xl border border-sand-200 bg-white p-4 lg:p-5">
                    <div class="flex flex-wrap items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-display text-[1.0625rem] font-bold text-forest-950">
                                    {{ __('messages.account_center.disputes_order', ['reference' => $dispute->order?->reference_code ?? '#'.$dispute->order_id]) }}
                                </span>
                                <x-account.status-pill :label="$dispute->status->label()" :color="$dispute->status->color()" />
                            </div>
                            <p class="mt-1 text-[1.125rem] font-medium text-ink">{{ $dispute->category->label() }}</p>
                            <p class="mt-1 line-clamp-2 text-[1.0625rem] text-ink-soft">{{ $dispute->description }}</p>
                            <p class="mt-1 text-[0.9375rem] text-ink-soft">
                                {{ __('messages.account_center.disputes_opened_on', ['date' => $dispute->created_at?->isoFormat('D MMM YYYY')]) }}
                                @if ($dispute->respondentCompany) · {{ $dispute->respondentCompany->name }} @endif
                            </p>
                        </div>
                        <a href="{{ route('disputes.show', ['order' => $dispute->order_id, 'dispute' => $dispute->getKey()]) }}"
                           class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-forest-700 px-4 py-2 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800">
                            {{ __('messages.account_center.disputes_view') }} <x-heroicon-m-arrow-right class="h-4 w-4" />
                        </a>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-5"><x-account.pagination :paginator="$disputes" :noun="__('messages.account_center.noun_disputes')" /></div>
    @endif
</x-layouts.account>
