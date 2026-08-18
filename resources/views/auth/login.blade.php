<x-layouts.app title="Log In" description="Log in to your Cameroon Timber Hub buyer or supplier account.">
    <div class="flex min-h-[70vh] items-center justify-center bg-sand-50 px-4 py-12">
        <div class="w-full max-w-md">
            <a href="{{ route('home') }}" class="mb-6 flex justify-center">
                <img src="/brand/logo-600.png" alt="Cameroon Timber Hub" class="h-12 w-auto" width="600" height="200">
            </a>

            <div class="rounded-2xl border border-sand-200 bg-white p-8 shadow-sm">
                <h1 class="text-2xl font-semibold text-ink">Log In</h1>
                <p class="mt-1 text-sm text-ink-soft">Welcome back. Sign in to your account.</p>

                @if ($errors->any())
                    <div class="mt-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-5">
                    @csrf

                    <div>
                        <label for="email" class="block text-sm font-medium text-ink">Email address</label>
                        <input id="email" name="email" type="email" required autofocus autocomplete="email"
                               value="{{ old('email') }}"
                               class="mt-1.5 w-full rounded-lg border border-sand-300 px-3 py-2.5 text-sm text-ink focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500">
                        @error('email')<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-ink">Password</label>
                        <input id="password" name="password" type="password" required autocomplete="current-password"
                               class="mt-1.5 w-full rounded-lg border border-sand-300 px-3 py-2.5 text-sm text-ink focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500">
                        @error('password')<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <label class="flex items-center gap-2 text-sm text-ink-soft">
                        <input type="checkbox" name="remember" value="1" @checked(old('remember'))
                               class="h-4 w-4 rounded border-sand-300 text-forest-700 focus:ring-forest-500">
                        Remember me
                    </label>

                    <button type="submit"
                            class="w-full rounded-lg bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">
                        Log In
                    </button>
                </form>
            </div>

            <p class="mt-6 text-center text-sm text-ink-soft">
                Don't have an account?
                <a href="{{ route('register') }}" class="font-semibold text-forest-700 hover:text-forest-800">Create one</a>
            </p>
        </div>
    </div>
</x-layouts.app>
