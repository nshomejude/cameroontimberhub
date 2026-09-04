<x-layouts.app title="Two-Factor Authentication" description="Manage two-factor authentication for your account." :noindex="true">
    <div class="mx-auto max-w-xl px-4 py-12">
        <h1 class="text-2xl font-semibold text-gray-900">Two-factor authentication</h1>

        @if (session('status'))
            <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($user->hasTwoFactorEnabled())
            <p class="mt-4 text-sm text-gray-600">Two-factor authentication is <strong>enabled</strong> on your account.</p>

            <form method="POST" action="{{ route('two-factor.recovery-codes') }}" class="mt-6">
                @csrf
                <button type="submit" class="rounded-md bg-gray-800 px-4 py-2 text-sm font-medium text-white">
                    Regenerate recovery codes
                </button>
            </form>

            <form method="POST" action="{{ route('two-factor.disable') }}" class="mt-6 space-y-3">
                @csrf
                <label class="block text-sm font-medium text-gray-700" for="password">Current password</label>
                <input id="password" type="password" name="password" required class="block w-full rounded-md border-gray-300">
                <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white">
                    Disable two-factor authentication
                </button>
            </form>
        @elseif (session('two_factor_secret') || $secret)
            <p class="mt-4 text-sm text-gray-600">
                Scan this QR code with your authenticator app (Google Authenticator, Authy, 1Password, etc.),
                then enter the 6-digit code it shows to confirm setup.
            </p>

            <div class="mt-4">{!! session('two_factor_qr', $qrSvg) !!}</div>

            <p class="mt-2 text-xs text-gray-500">
                Can't scan? Enter this code manually: <code>{{ session('two_factor_secret', $secret) }}</code>
            </p>

            <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-6 space-y-3">
                @csrf
                <label class="block text-sm font-medium text-gray-700" for="code">6-digit code</label>
                <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required class="block w-full rounded-md border-gray-300">
                <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white">
                    Confirm
                </button>
            </form>
        @else
            <p class="mt-4 text-sm text-gray-600">
                Two-factor authentication is not enabled. Enabling it adds a one-time code from your phone to every
                login, on top of your password.
            </p>

            <form method="POST" action="{{ route('two-factor.enable') }}" class="mt-6">
                @csrf
                <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white">
                    Enable two-factor authentication
                </button>
            </form>
        @endif
    </div>
</x-layouts.app>
