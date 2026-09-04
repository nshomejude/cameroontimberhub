<x-layouts.app title="Recovery Codes" description="Save your two-factor recovery codes." :noindex="true">
    <div class="mx-auto max-w-xl px-4 py-12">
        <h1 class="text-2xl font-semibold text-gray-900">Save your recovery codes</h1>
        <p class="mt-2 text-sm text-gray-600">
            Store these somewhere safe. Each code can be used once to sign in if you lose access to your
            authenticator app. They will not be shown again.
        </p>

        <ul class="mt-6 grid grid-cols-2 gap-2 rounded-md bg-gray-50 p-4 font-mono text-sm">
            @foreach ($codes as $code)
                <li>{{ $code }}</li>
            @endforeach
        </ul>

        <a href="{{ route('two-factor.show') }}" class="mt-6 inline-block rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white">
            Done
        </a>
    </div>
</x-layouts.app>
