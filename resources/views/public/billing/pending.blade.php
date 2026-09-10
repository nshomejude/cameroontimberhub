<x-layouts.app :title="__('messages.billing.pending_title')" noindex>
    <div class="mx-auto max-w-md px-4 py-16 text-center" id="billing-pending"
        data-status-url="{{ route('billing.checkout.status', $payment) }}"
        data-picker-url="{{ $payment->plan ? route('billing.checkout', $payment->plan) : route('pricing') }}">
        <h1 class="font-display text-2xl font-semibold text-forest-950 dark:text-sand-100">{{ __('messages.billing.pending_title') }}</h1>
        <p class="mt-4 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.billing.pending_body') }}</p>
        <p class="mt-6 text-[0.75rem] text-ink-soft dark:text-[#8f887b]">{{ __('messages.billing.reference') }}: {{ $payment->provider_reference }}</p>
        <p class="mt-8 hidden text-[0.8125rem] text-red-700 dark:text-red-300" id="billing-pending-failed">{{ __('messages.billing.momo_push_failed') }}</p>
    </div>

    <script>
        (function () {
            var el = document.getElementById('billing-pending');
            if (!el) return;
            var statusUrl = el.dataset.statusUrl;
            var pickerUrl = el.dataset.pickerUrl;
            var poll = setInterval(function () {
                fetch(statusUrl, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.status === 'completed') {
                            clearInterval(poll);
                            window.location = data.success_url;
                        } else if (data.status === 'failed' || data.status === 'cancelled') {
                            clearInterval(poll);
                            document.getElementById('billing-pending-failed').classList.remove('hidden');
                            setTimeout(function () { window.location = pickerUrl; }, 2500);
                        }
                    })
                    .catch(function () {});
            }, 4000);
        })();
    </script>
</x-layouts.app>
