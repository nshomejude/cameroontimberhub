<x-layouts.app :title="__('messages.billing.success_title')" noindex>
    <div class="mx-auto max-w-xl px-4 py-14">
        <div class="rounded-2xl border border-forest-200 bg-forest-50 p-6 dark:border-forest-800 dark:bg-forest-950/40">
            <h1 class="font-display text-2xl font-semibold text-forest-950 dark:text-sand-100">{{ __('messages.billing.success_title') }}</h1>
            <p class="mt-2 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">
                {{ __('messages.billing.success_body', ['plan' => $plan?->name ?? '']) }}
            </p>
        </div>

        <dl class="mt-6 divide-y divide-sand-200 rounded-2xl border border-sand-200 bg-white text-[0.875rem] dark:divide-[#2c2a24] dark:border-[#2c2a24] dark:bg-[#1f1d18]">
            <div class="flex justify-between px-5 py-3">
                <dt class="text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.billing.plan') }}</dt>
                <dd class="font-medium text-forest-900 dark:text-sand-100">{{ $plan?->name }}</dd>
            </div>
            <div class="flex justify-between px-5 py-3">
                <dt class="text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.billing.amount_paid') }}</dt>
                <dd class="font-medium text-forest-900 dark:text-sand-100">{{ number_format((float) $payment->amount) }} {{ $payment->currency }}</dd>
            </div>
            @if ($subscription?->renews_at)
                <div class="flex justify-between px-5 py-3">
                    <dt class="text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.billing.renews_at') }}</dt>
                    <dd class="font-medium text-forest-900 dark:text-sand-100">{{ $subscription->renews_at->toFormattedDateString() }}</dd>
                </div>
            @endif
            @if ($receipt)
                <div class="flex justify-between px-5 py-3">
                    <dt class="text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.billing.receipt_number') }}</dt>
                    <dd class="font-medium text-forest-900 dark:text-sand-100">
                        <a href="{{ $receipt->verificationUrl() }}" class="text-forest-700 underline hover:text-forest-800">{{ $receipt->receipt_number }}</a>
                    </dd>
                </div>
            @endif
        </dl>

        <a href="{{ route('billing.overview') }}" class="mt-6 inline-flex rounded-full border border-forest-300 px-4 py-2 text-[0.8125rem] font-semibold text-forest-700 transition hover:bg-forest-50 dark:border-forest-700 dark:text-forest-300">
            {{ __('messages.billing.go_to_billing') }}
        </a>
    </div>
</x-layouts.app>
