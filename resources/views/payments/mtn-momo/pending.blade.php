<x-layouts.app
    title="Check your phone to approve this payment"
    description="A payment request was sent to your phone via MTN Mobile Money."
    noindex>

    <div class="mx-auto max-w-md px-4 py-16 text-center">
        <h1 class="text-2xl font-semibold text-gray-900">Check your phone</h1>
        <p class="mt-4 text-gray-600">
            We've sent a payment approval request to your phone via MTN Mobile Money.
            Approve it there to complete your payment. This page will update once we
            receive confirmation.
        </p>
        <p class="mt-6 text-sm text-gray-400">Reference: {{ $payment->provider_reference }}</p>
    </div>
</x-layouts.app>
