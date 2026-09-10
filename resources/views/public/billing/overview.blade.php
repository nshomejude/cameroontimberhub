<x-layouts.app :title="__('messages.billing.overview_title')" noindex>
    <div class="mx-auto max-w-2xl px-4 py-12">
        <h1 class="font-display text-2xl font-semibold text-forest-950 dark:text-sand-100">{{ __('messages.billing.overview_title') }}</h1>

        <div class="mt-6 rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
            <p class="text-[0.75rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.billing.current_plan') }}</p>
            <p class="mt-1 font-display text-lg font-semibold text-forest-900 dark:text-sand-100">{{ $plan?->name ?? __('messages.billing.no_plan') }}</p>
            @if ($subscription?->renews_at)
                <p class="mt-1 text-[0.8125rem] text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.billing.renews_at') }}: {{ $subscription->renews_at->toFormattedDateString() }}</p>
            @endif
            <a href="{{ route('pricing') }}" class="mt-4 inline-flex rounded-full bg-forest-700 px-4 py-2 text-[0.8125rem] font-semibold text-white transition hover:bg-forest-800">
                {{ __('messages.billing.change_plan') }}
            </a>
        </div>

        <h2 class="mt-8 font-display text-lg font-semibold text-forest-950 dark:text-sand-100">{{ __('messages.billing.past_receipts') }}</h2>
        @if ($receipts->isEmpty())
            <p class="mt-2 text-[0.875rem] text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.billing.no_receipts') }}</p>
        @else
            <ul class="mt-3 divide-y divide-sand-200 rounded-2xl border border-sand-200 bg-white text-[0.875rem] dark:divide-[#2c2a24] dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                @foreach ($receipts as $receipt)
                    <li class="flex items-center justify-between px-5 py-3">
                        <a href="{{ $receipt->verificationUrl() }}" class="font-medium text-forest-700 underline hover:text-forest-800">{{ $receipt->receipt_number }}</a>
                        <span class="text-ink-soft dark:text-[#b3ab9b]">{{ $receipt->money() }} · {{ $receipt->issued_at?->toFormattedDateString() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layouts.app>
