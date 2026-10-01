<x-filament-panels::page>
    @php
        $code      = $this->getCode();
        $shareUrl  = $this->getShareUrl();
        $funnel    = $this->getFunnel();
        $totals    = $this->getTotals();
        $earnings  = $this->getEarnings();
        $profile   = $this->getProfile();
        $muted     = 'text-sm text-gray-500 dark:text-gray-400';
    @endphp

    <div class="space-y-6">
        {{-- Link + code --}}
        <x-filament::section :heading="__('messages.referral_center.your_link')">
            <p class="{{ $muted }} mb-4">{{ $this->getIntro() }}</p>

            <div x-data="{ copied: false }" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <input type="text" readonly value="{{ $shareUrl }}" aria-label="{{ __('messages.referral_center.your_link') }}"
                       class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                       x-on:focus="$event.target.select()">
                <x-filament::button color="primary" icon="heroicon-o-clipboard-document"
                    x-on:click="navigator.clipboard.writeText(@js($shareUrl)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                    <span x-show="! copied">{{ __('messages.referral_center.copy') }}</span>
                    <span x-show="copied" x-cloak>{{ __('messages.referral_center.copied') }}</span>
                </x-filament::button>
            </div>

            <p class="mt-3 {{ $muted }}">
                {{ __('messages.referral_center.your_code') }}:
                <span class="font-mono font-semibold text-gray-900 dark:text-white">{{ $code }}</span>
            </p>
        </x-filament::section>

        {{-- Funnel --}}
        <div class="grid gap-4 sm:grid-cols-3">
            @foreach (['signed_up', 'verified', 'qualified'] as $key)
                <x-filament::section>
                    <p class="{{ $muted }}">{{ __('messages.referral_center.stats_'.$key) }}</p>
                    <p class="mt-1 text-3xl font-bold text-gray-900 dark:text-white">{{ $funnel[$key] }}</p>
                    <p class="mt-1 text-xs text-gray-400">{{ __('messages.referral_center.stats_'.$key.'_hint') }}</p>
                </x-filament::section>
            @endforeach
        </div>

        {{-- Totals per currency --}}
        <x-filament::section :heading="__('messages.referral_center.totals_title')">
            @if ($totals === [])
                <p class="{{ $muted }}">{{ __('messages.referral_center.totals_empty') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="py-2 pe-4 font-medium">{{ __('messages.referral_center.totals_currency') }}</th>
                                <th class="py-2 pe-4 font-medium">{{ __('messages.referral_center.totals_earned') }}</th>
                                <th class="py-2 pe-4 font-medium">{{ __('messages.referral_center.totals_paid') }}</th>
                                <th class="py-2 font-medium">{{ __('messages.referral_center.totals_pending') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-900 dark:text-white">
                            @foreach ($totals as $currency => $t)
                                <tr>
                                    <td class="py-2 pe-4 font-semibold">{{ $currency }}</td>
                                    <td class="py-2 pe-4">{{ $t['earned'] }}</td>
                                    <td class="py-2 pe-4">{{ $t['paid'] }}</td>
                                    <td class="py-2">{{ $t['pending'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- Earnings --}}
        <x-filament::section :heading="__('messages.referral_center.earnings_title')">
            @if ($earnings->isEmpty())
                <p class="{{ $muted }}">{{ __('messages.referral_center.earnings_empty') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="py-2 pe-4 font-medium">{{ __('messages.referral_center.col_date') }}</th>
                                <th class="py-2 pe-4 font-medium">{{ __('messages.referral_center.col_source') }}</th>
                                <th class="py-2 pe-4 font-medium">{{ __('messages.referral_center.col_amount') }}</th>
                                <th class="py-2 pe-4 font-medium">{{ __('messages.referral_center.col_payout') }}</th>
                                <th class="py-2 font-medium">{{ __('messages.referral_center.col_method') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-900 dark:text-white">
                            @foreach ($earnings as $earning)
                                @php($payoutStatus = $earning->payoutStatus())
                                <tr>
                                    <td class="py-2 pe-4 whitespace-nowrap">{{ $earning->created_at?->format('d M Y') }}</td>
                                    <td class="py-2 pe-4">{{ $earning->source_reference }}</td>
                                    <td class="py-2 pe-4 whitespace-nowrap font-semibold">{{ $earning->amountLabel() }}</td>
                                    <td class="py-2 pe-4">
                                        <x-filament::badge :color="match ($payoutStatus) {
                                            'paid' => 'success',
                                            'failed', 'cancelled' => 'danger',
                                            'processing', 'unclaimed' => 'warning',
                                            default => 'gray',
                                        }">
                                            {{ __('messages.referral_center.payout_status.'.$payoutStatus) }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="py-2 whitespace-nowrap">
                                        @if ($payoutStatus === 'paid' && $earning->latestPayout)
                                            {{ __('messages.referral_center.method_'.$earning->latestPayout->method) }}
                                            @if ($earning->paid_at)
                                                <span class="text-gray-400">· {{ $earning->paid_at->format('d M Y') }}</span>
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- Payout details --}}
        <x-filament::section :heading="__('messages.referral_center.payout_title')" :description="__('messages.referral_center.payout_subtitle')">
            <div class="grid gap-6 md:grid-cols-2">
                <div class="space-y-3">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('messages.referral_center.paypal_heading') }}</h3>
                    <p class="text-sm text-gray-700 dark:text-gray-300">
                        @if ($profile?->maskedPaypalEmail())
                            {{ __('messages.referral_center.paypal_current', ['email' => $profile->maskedPaypalEmail()]) }}
                        @else
                            {{ __('messages.referral_center.paypal_none') }}
                        @endif
                    </p>
                    <p class="{{ $muted }}">
                        @if ($this->paypalAvailable())
                            {{ __('messages.referral_center.paypal_currencies', ['currencies' => implode(', ', $this->paypalCurrencies())]) }}
                        @else
                            {{ __('messages.referral_center.paypal_unavailable') }}
                        @endif
                    </p>
                    <div class="flex flex-wrap gap-2">
                        {{ $this->setPaypalEmailAction }}
                        {{ $this->removePaypalEmailAction }}
                    </div>
                </div>

                <div class="space-y-3">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('messages.referral_center.manual_heading') }}</h3>
                    <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                        {{ __('messages.referral_center.xaf_note') }}
                    </p>
                    <p class="text-sm text-gray-700 dark:text-gray-300 break-words">
                        @if ($profile?->maskedManualPayoutDetails())
                            {{ __('messages.referral_center.manual_current', ['details' => $profile->maskedManualPayoutDetails()]) }}
                        @else
                            {{ __('messages.referral_center.manual_none') }}
                        @endif
                    </p>
                    <div class="flex flex-wrap gap-2">
                        {{ $this->setManualDetailsAction }}
                        {{ $this->removeManualDetailsAction }}
                    </div>
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
