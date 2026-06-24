<x-filament-panels::page>
    @php
        $company      = $this->getCompany();
        $subscription = $this->getActiveSubscription();
        $plan         = $subscription?->plan ?? $company?->plan;
    @endphp

    <div class="space-y-6">
        {{-- Current plan card --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-4">Current plan</h2>

            @if($plan)
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ $plan->name }}</p>
                        @if($plan->price_monthly_usd)
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                ${{ number_format($plan->price_monthly_usd, 0) }} / month
                            </p>
                        @else
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Custom pricing</p>
                        @endif
                    </div>
                    <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900 px-3 py-1 text-sm font-medium text-green-700 dark:text-green-300">
                        Active
                    </span>
                </div>

                @if($subscription)
                    <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">Started</dt>
                            <dd class="font-medium text-gray-900 dark:text-white">
                                {{ $subscription->starts_at?->format('d M Y') ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">Renews</dt>
                            <dd class="font-medium text-gray-900 dark:text-white">
                                {{ $subscription->ends_at?->format('d M Y') ?? 'No end date' }}
                            </dd>
                        </div>
                    </dl>
                @endif

                {{-- Features --}}
                @if($plan->features && count($plan->features))
                    <div class="mt-6">
                        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Included features</p>
                        <ul class="space-y-2">
                            @foreach($plan->features as $feature => $value)
                                @if($value)
                                    <li class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                        <x-heroicon-o-check-circle class="w-4 h-4 text-green-500 shrink-0"/>
                                        {{ str_replace('_', ' ', $feature) }}
                                        @if(!is_bool($value))
                                            <span class="text-gray-400">({{ $value }})</span>
                                        @endif
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </div>
                @endif
            @else
                <div class="text-center py-8">
                    <x-heroicon-o-credit-card class="mx-auto h-10 w-10 text-gray-300 dark:text-gray-600"/>
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        No active subscription. Contact us to upgrade your listing.
                    </p>
                </div>
            @endif
        </div>

        {{-- Upgrade CTA --}}
        <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-5">
            <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">
                Want to access more leads and better visibility?
            </p>
            <p class="mt-1 text-sm text-amber-700 dark:text-amber-400">
                Speak to our team about upgrading to a Professional or Enterprise plan.
                See the <a href="{{ route('pricing') }}" target="_blank" class="underline font-medium">pricing page</a>
                for details.
            </p>
        </div>
    </div>
</x-filament-panels::page>
