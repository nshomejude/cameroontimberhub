<x-layouts.app :title="__('messages.billing.overview_title')" noindex>
    @php
        $badgeClasses = [
            'success' => 'bg-forest-100 text-forest-800 dark:bg-forest-950/60 dark:text-forest-300',
            'info' => 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300',
            'warning' => 'bg-amber-100 text-amber-900 dark:bg-amber-950/60 dark:text-amber-200',
            'danger' => 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300',
            'gray' => 'bg-sand-200 text-ink-soft dark:bg-[#2c2a24] dark:text-[#b3ab9b]',
        ];
        $card = 'rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]';
        $muted = 'text-ink-soft dark:text-[#b3ab9b]';
    @endphp

    <div class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="font-display text-2xl font-semibold text-forest-950 dark:text-sand-100">{{ __('messages.billing.overview_title') }}</h1>

        @if ($company === null)
            {{-- Buyer / staff user with no company subscription. --}}
            <div class="mt-6 {{ $card }}">
                <p class="font-display text-lg font-semibold text-forest-900 dark:text-sand-100">{{ __('messages.billing.no_company_title') }}</p>
                <p class="mt-1 text-[0.875rem] {{ $muted }}">{{ __('messages.billing.no_company_body') }}</p>
                <a href="{{ route('pricing') }}" class="mt-4 inline-flex rounded-full bg-forest-700 px-4 py-2 text-[0.8125rem] font-semibold text-white transition hover:bg-forest-800">
                    {{ __('messages.billing.see_paid_plans') }}
                </a>
            </div>
        @else
            <p class="mt-1 text-[0.8125rem] {{ $muted }}">{{ $company->name }}</p>

            {{-- 1. Current plan ------------------------------------------------ --}}
            @php
                $isFree = $plan === null || (float) $plan->price_amount <= 0.0;
                $status = $subscription?->status;
            @endphp
            <div class="mt-6 {{ $card }}">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-[0.75rem] uppercase tracking-wide {{ $muted }}">{{ __('messages.billing.current_plan') }}</p>
                        <p class="mt-1 font-display text-lg font-semibold text-forest-900 dark:text-sand-100">
                            {{ $plan?->name ?? __('messages.billing.free_plan') }}
                        </p>
                        @if ($plan?->segment)
                            <p class="text-[0.8125rem] {{ $muted }}">{{ \Illuminate\Support\Str::headline($plan->segment) }}</p>
                        @endif
                        @unless ($isFree)
                            <p class="mt-1 text-[0.875rem] text-forest-900 dark:text-sand-100">
                                {{ number_format((float) $plan->price_amount) }} {{ $plan->price_currency }}
                                <span class="{{ $muted }}">/ {{ $plan->billing_period }}</span>
                            </p>
                        @endunless
                    </div>
                    @if ($status)
                        <span class="shrink-0 rounded-full px-2.5 py-1 text-[0.75rem] font-semibold {{ $badgeClasses[$status->color()] ?? $badgeClasses['gray'] }}">
                            {{ $status->label() }}
                        </span>
                    @endif
                </div>

                <p class="mt-3 text-[0.8125rem] {{ $muted }}">
                    @if ($isFree && $subscription === null)
                        {{ __('messages.billing.on_free_plan') }}
                    @elseif ($subscription && $subscription->onTrial())
                        {{ __('messages.billing.trial_ends', ['date' => $subscription->trial_ends_at->toFormattedDateString()]) }}
                    @elseif ($subscription && $subscription->inGrace())
                        {{ __('messages.billing.in_grace_until', ['date' => $subscription->grace_until->toFormattedDateString()]) }}
                    @elseif ($subscription && $subscription->entitled() && $subscription->renews_at)
                        {{ __('messages.billing.renews_on', ['date' => $subscription->renews_at->toFormattedDateString()]) }}
                    @elseif ($subscription && ! $subscription->entitled())
                        {{ __('messages.billing.lapsed_on_free') }}
                    @endif
                </p>

                <a href="{{ route('pricing') }}" class="mt-4 inline-flex rounded-full bg-forest-700 px-4 py-2 text-[0.8125rem] font-semibold text-white transition hover:bg-forest-800">
                    {{ $isFree ? __('messages.billing.see_paid_plans') : __('messages.billing.change_plan') }}
                </a>
            </div>

            {{-- 2. Invoices --------------------------------------------------- --}}
            <h2 class="mt-8 font-display text-lg font-semibold text-forest-950 dark:text-sand-100">{{ __('messages.billing.invoices') }}</h2>
            @if ($invoices->isEmpty())
                <p class="mt-2 text-[0.875rem] {{ $muted }}">{{ __('messages.billing.no_invoices') }}</p>
            @else
                <div class="mt-3 overflow-x-auto rounded-2xl border border-sand-200 dark:border-[#2c2a24]">
                    <table class="w-full text-[0.875rem]">
                        <thead class="bg-sand-100 text-left text-[0.75rem] uppercase tracking-wide {{ $muted }} dark:bg-[#26241f]">
                            <tr>
                                <th class="px-4 py-2 font-medium">{{ __('messages.billing.invoice_number') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('messages.billing.invoice_issued') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('messages.billing.invoice_amount') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('messages.billing.invoice_status') }}</th>
                                <th class="px-4 py-2 font-medium"><span class="sr-only">{{ __('messages.billing.actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sand-200 bg-white dark:divide-[#2c2a24] dark:bg-[#1f1d18]">
                            @foreach ($invoices as $invoice)
                                <tr>
                                    <td class="px-4 py-2.5 font-medium text-forest-900 dark:text-sand-100">{{ $invoice->invoice_number }}</td>
                                    <td class="px-4 py-2.5 {{ $muted }}">{{ $invoice->issued_at?->toFormattedDateString() }}</td>
                                    <td class="px-4 py-2.5 {{ $muted }}">{{ $invoice->money() }}</td>
                                    <td class="px-4 py-2.5">
                                        <span class="rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold {{ $badgeClasses[$invoice->status->color()] ?? $badgeClasses['gray'] }}">{{ $invoice->status->label() }}</span>
                                    </td>
                                    <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                        <a href="{{ route('billing.invoices.show', $invoice) }}" class="font-medium text-forest-700 underline hover:text-forest-800">{{ __('messages.billing.view') }}</a>
                                        <a href="{{ route('billing.invoices.show', ['invoice' => $invoice, 'format' => 'pdf']) }}" class="ml-3 font-medium text-forest-700 underline hover:text-forest-800">{{ __('messages.billing.pdf') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($invoices->count() >= $cap)
                    <p class="mt-2 text-[0.75rem] {{ $muted }}">{{ __('messages.billing.showing_recent', ['count' => $cap]) }}</p>
                @endif
            @endif

            {{-- 3. Receipts ------------------------------------------------- --}}
            <h2 class="mt-8 font-display text-lg font-semibold text-forest-950 dark:text-sand-100">{{ __('messages.billing.past_receipts') }}</h2>
            @if ($receipts->isEmpty())
                <p class="mt-2 text-[0.875rem] {{ $muted }}">{{ __('messages.billing.no_receipts') }}</p>
            @else
                <ul class="mt-3 divide-y divide-sand-200 rounded-2xl border border-sand-200 bg-white text-[0.875rem] dark:divide-[#2c2a24] dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                    @foreach ($receipts as $receipt)
                        <li class="flex items-center justify-between px-5 py-3">
                            <a href="{{ $receipt->verificationUrl() }}" class="font-medium text-forest-700 underline hover:text-forest-800">{{ $receipt->receipt_number }}</a>
                            <span class="{{ $muted }}">{{ $receipt->money() }} · {{ $receipt->issued_at?->toFormattedDateString() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            {{-- 4. Payment history ---------------------------------------- --}}
            <h2 class="mt-8 font-display text-lg font-semibold text-forest-950 dark:text-sand-100">{{ __('messages.billing.payment_history') }}</h2>
            @if ($payments->isEmpty())
                <p class="mt-2 text-[0.875rem] {{ $muted }}">{{ __('messages.billing.no_payments') }}</p>
            @else
                <div class="mt-3 overflow-x-auto rounded-2xl border border-sand-200 dark:border-[#2c2a24]">
                    <table class="w-full text-[0.875rem]">
                        <thead class="bg-sand-100 text-left text-[0.75rem] uppercase tracking-wide {{ $muted }} dark:bg-[#26241f]">
                            <tr>
                                <th class="px-4 py-2 font-medium">{{ __('messages.billing.payment_date') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('messages.billing.method') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('messages.billing.invoice_amount') }}</th>
                                <th class="px-4 py-2 font-medium">{{ __('messages.billing.invoice_status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sand-200 bg-white dark:divide-[#2c2a24] dark:bg-[#1f1d18]">
                            @foreach ($payments as $payment)
                                <tr>
                                    <td class="px-4 py-2.5 {{ $muted }}">{{ ($payment->paid_at ?? $payment->created_at)?->toFormattedDateString() }}</td>
                                    <td class="px-4 py-2.5 text-forest-900 dark:text-sand-100">{{ $payment->provider?->label() ?? '—' }}</td>
                                    <td class="px-4 py-2.5 {{ $muted }}">{{ number_format((float) $payment->amount) }} {{ $payment->currency }}</td>
                                    <td class="px-4 py-2.5 {{ $muted }}">{{ $payment->status->label() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- 5. Payment method ---------------------------------------- --}}
            <h2 class="mt-8 font-display text-lg font-semibold text-forest-950 dark:text-sand-100">{{ __('messages.billing.payment_method') }}</h2>
            <p class="mt-2 text-[0.875rem] {{ $muted }}">{{ __('messages.billing.payment_method_note') }}</p>
        @endif
    </div>
</x-layouts.app>
