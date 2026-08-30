<x-layouts.app
    title="Payment successful"
    description="Your PayPal payment was completed."
    noindex>

    <div class="mx-auto max-w-md px-4 py-16 text-center">
        <h1 class="text-2xl font-semibold text-gray-900">Payment successful</h1>
        <p class="mt-4 text-gray-600">
            Your payment via PayPal has been completed. Thank you!
        </p>
        <p class="mt-6 text-sm text-gray-400">Reference: {{ $payment->provider_reference }}</p>
    </div>
</x-layouts.app>
