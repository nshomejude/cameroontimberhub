<x-layouts.app :title="$provider.' is not available'">
    <div class="max-w-xl mx-auto py-16 px-4 text-center">
        <h1 class="text-2xl font-semibold text-gray-900">{{ $provider }} isn't set up yet</h1>
        <p class="mt-4 text-gray-600">
            This payment method is not currently configured. Please choose a different payment
            option, or contact support if you believe this is an error.
        </p>
        <a href="{{ url()->previous() }}" class="mt-8 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500">
            &larr; Go back
        </a>
    </div>
</x-layouts.app>
