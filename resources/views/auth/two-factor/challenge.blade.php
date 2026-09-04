<x-layouts.app title="Re-verify" description="Confirm a two-factor code to continue." :noindex="true">
    <div class="mx-auto max-w-md px-4 py-12">
        <h1 class="text-2xl font-semibold text-gray-900">Confirm it's you</h1>
        <p class="mt-2 text-sm text-gray-600">
            This action requires a recent two-factor confirmation. Enter a code from your authenticator app
            (or a recovery code) to continue.
        </p>

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('two-factor.challenge.store') }}" class="mt-6 space-y-3">
            @csrf
            <input type="hidden" name="redirect_to" value="{{ $redirectTo }}">
            <label class="block text-sm font-medium text-gray-700" for="code">Code</label>
            <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required class="block w-full rounded-md border-gray-300">
            <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white">
                Verify
            </button>
        </form>
    </div>
</x-layouts.app>
