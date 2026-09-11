<x-layouts.app :title="__('messages.billing.checkout_title', ['plan' => $plan->name])" noindex>
    <div class="mx-auto max-w-xl px-4 py-12">
        <a href="{{ route('pricing') }}" class="text-[0.8125rem] font-medium text-forest-700 hover:text-forest-800">&larr; {{ __('messages.billing.back_to_pricing') }}</a>

        <h1 class="mt-4 font-display text-2xl font-semibold text-forest-950 dark:text-sand-100">{{ __('messages.billing.checkout_title', ['plan' => $plan->name]) }}</h1>

        <div class="mt-5 rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
            <p class="font-display text-lg font-semibold text-forest-900 dark:text-sand-100">{{ $plan->name }}</p>
            <p class="mt-1 text-[0.8125rem] text-ink-soft dark:text-[#b3ab9b]">{{ $plan->description }}</p>
            @php($taxed = ($breakdown['rule_id'] ?? null) !== null)
            @if ($taxed)
                <dl class="mt-3 space-y-1 text-[0.875rem]">
                    <div class="flex justify-between text-ink-soft dark:text-[#b3ab9b]">
                        <dt>{{ __('messages.billing.tax_subtotal') }}</dt>
                        <dd>{{ number_format((float) $breakdown['subtotal']) }} {{ $plan->price_currency }}</dd>
                    </div>
                    <div class="flex justify-between text-ink-soft dark:text-[#b3ab9b]">
                        <dt>{{ __('messages.billing.tax_line', ['label' => $breakdown['tax_label'], 'rate' => rtrim(rtrim(number_format(((float) $breakdown['tax_rate']) * 100, 2), '0'), '.')]) }}</dt>
                        <dd>{{ number_format((float) $breakdown['tax_amount']) }} {{ $plan->price_currency }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-sand-200 pt-1 font-semibold text-forest-950 dark:border-[#2c2a24] dark:text-sand-100">
                        <dt>{{ __('messages.billing.tax_total') }}</dt>
                        <dd>{{ number_format((float) $breakdown['total']) }} {{ $plan->price_currency }} / {{ $plan->billing_period }}</dd>
                    </div>
                </dl>
            @else
                <p class="mt-3">
                    <span class="font-display text-2xl font-semibold text-forest-950 dark:text-sand-100">{{ number_format((float) ($breakdown['total'] ?? $plan->price_amount)) }}</span>
                    <span class="text-[0.8125rem] text-ink-soft dark:text-[#b3ab9b]">{{ $plan->price_currency }} / {{ $plan->billing_period }}</span>
                </p>
            @endif
        </div>

        @if ($canStartTrial ?? false)
            <div class="mt-6 rounded-2xl border border-forest-200 bg-forest-50/60 p-5 dark:border-forest-800 dark:bg-forest-950/30">
                @if ($errors->has('trial'))
                    <p class="mb-3 text-[0.8125rem] text-red-700 dark:text-red-300">{{ $errors->first('trial') }}</p>
                @endif
                <form method="POST" action="{{ route('billing.checkout.trial', $plan) }}">
                    @csrf
                    <button type="submit" class="w-full rounded-xl bg-forest-700 px-4 py-2.5 text-[0.875rem] font-semibold text-white hover:bg-forest-800">
                        {{ __('messages.billing.start_trial', ['days' => $plan->trial_days]) }}
                    </button>
                </form>
                <p class="mt-2 text-[0.75rem] text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.billing.trial_no_card') }}</p>
            </div>
        @endif

        @if (! $anyConfigured)
            <div class="mt-6 rounded-2xl border border-amber-300 bg-amber-50 p-5 text-[0.875rem] text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                {{ __('messages.billing.no_online_payment') }}
                <a href="{{ route('contact') }}" class="font-semibold underline">{{ __('messages.billing.contact_sales') }}</a>
            </div>
        @else
            @if ($errors->any())
                <div class="mt-6 rounded-xl border border-red-300 bg-red-50 p-3 text-[0.8125rem] text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('payments.checkout', $plan) }}" class="mt-6 space-y-4">
                @csrf
                <fieldset class="space-y-3">
                    <legend class="text-[0.8125rem] font-semibold text-forest-900 dark:text-sand-100">{{ __('messages.billing.choose_method') }}</legend>
                    @foreach ($providers as $provider)
                        <label @class([
                            'flex items-start gap-3 rounded-xl border p-4',
                            'border-sand-200 dark:border-[#2c2a24]' => $provider['configured'],
                            'border-sand-200 opacity-50 dark:border-[#2c2a24]' => ! $provider['configured'],
                        ])>
                            <input type="radio" name="provider" value="{{ $provider['value'] }}" class="mt-1"
                                @disabled(! $provider['configured'])
                                @checked($loop->first && $provider['configured'])>
                            <span>
                                <span class="block text-[0.875rem] font-medium text-forest-900 dark:text-sand-100">{{ $provider['label'] }}</span>
                                @unless ($provider['configured'])
                                    <span class="block text-[0.75rem] text-ink-soft dark:text-[#8f887b]">{{ __('messages.billing.method_unavailable') }}</span>
                                @endunless
                            </span>
                        </label>
                    @endforeach
                </fieldset>

                @if ($providers->contains('needs_msisdn', true))
                    <div>
                        <label for="msisdn" class="block text-[0.8125rem] font-semibold text-forest-900 dark:text-sand-100">{{ __('messages.billing.momo_phone_label') }}</label>
                        <input type="text" id="msisdn" name="msisdn" inputmode="tel" placeholder="6XXXXXXXX" value="{{ old('msisdn') }}"
                            class="mt-1 w-full rounded-xl border border-sand-200 bg-white px-3 py-2 text-[0.875rem] dark:border-[#2c2a24] dark:bg-[#14130f]">
                        <p class="mt-1 text-[0.75rem] text-ink-soft dark:text-[#8f887b]">{{ __('messages.billing.momo_phone_hint') }}</p>
                    </div>
                @endif

                <button type="submit" class="w-full rounded-full bg-forest-700 px-4 py-2.5 text-[0.875rem] font-semibold text-white transition hover:bg-forest-800">
                    {{ __('messages.billing.proceed') }}
                </button>
            </form>
        @endif
    </div>
</x-layouts.app>
