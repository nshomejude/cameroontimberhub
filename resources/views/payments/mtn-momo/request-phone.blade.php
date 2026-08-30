<x-layouts.app
    title="Pay with MTN Mobile Money"
    description="Enter your MTN Mobile Money phone number to receive a payment prompt."
    noindex>

    <div class="mx-auto max-w-md px-4 py-16">
        <h1 class="text-2xl font-semibold text-gray-900">Pay with MTN Mobile Money</h1>
        <p class="mt-2 text-gray-600">
            Enter the phone number registered with MTN Mobile Money. We'll send a payment
            approval request to that phone.
        </p>

        <form method="POST" action="{{ route('payments.mtn-momo.submit', $payment) }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="phone" class="block text-sm font-medium text-gray-700">Phone number</label>
                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    required
                    placeholder="e.g. 6XXXXXXXX"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                @error('phone')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full rounded-md bg-indigo-600 px-4 py-2 text-white font-medium hover:bg-indigo-700"
            >
                Send payment request
            </button>
        </form>
    </div>
</x-layouts.app>
