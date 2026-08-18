<x-layouts.app title="Create Account" description="Join Cameroon Timber Hub as a buyer or as a timber supplier.">
    <div class="flex min-h-[70vh] items-center justify-center bg-sand-50 px-4 py-12">
        <div class="w-full max-w-md">
            <a href="{{ route('home') }}" class="mb-6 flex justify-center">
                <img src="/brand/logo-600.png" alt="Cameroon Timber Hub" class="h-12 w-auto" width="600" height="200">
            </a>

            <div class="rounded-2xl border border-sand-200 bg-white p-8 shadow-sm">
                <h1 class="text-2xl font-semibold text-ink">Create your account</h1>
                <p class="mt-1 text-sm text-ink-soft">Join as a buyer, or list your company as a supplier.</p>

                @if ($errors->any())
                    <div class="mt-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('register.store') }}" class="mt-6 space-y-5"
                      x-data="{ accountType: '{{ old('account_type', 'buyer') }}' }">
                    @csrf

                    <div>
                        <span class="block text-sm font-medium text-ink">I am a</span>
                        <div class="mt-1.5 grid grid-cols-2 gap-2">
                            @foreach ([['buyer', 'Buyer', 'Source timber'], ['supplier', 'Supplier', 'Sell timber']] as [$value, $label, $hint])
                                <label class="cursor-pointer rounded-lg border px-3 py-3 text-center transition"
                                       :class="accountType === '{{ $value }}'
                                           ? 'border-forest-700 bg-forest-50 text-forest-700'
                                           : 'border-sand-300 text-ink hover:border-forest-500'">
                                    <input type="radio" name="account_type" value="{{ $value }}" class="sr-only"
                                           x-model="accountType" @checked(old('account_type', 'buyer') === $value)>
                                    <span class="block text-sm font-semibold">{{ $label }}</span>
                                    <span class="block text-[11px] text-ink-soft">{{ $hint }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('account_type')<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="name" class="block text-sm font-medium text-ink">Full name</label>
                        <input id="name" name="name" type="text" required autocomplete="name" value="{{ old('name') }}"
                               class="mt-1.5 w-full rounded-lg border border-sand-300 px-3 py-2.5 text-sm text-ink focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500">
                        @error('name')<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div x-show="accountType === 'supplier'" x-cloak>
                        <label for="company_name" class="block text-sm font-medium text-ink">Company name</label>
                        <input id="company_name" name="company_name" type="text" autocomplete="organization"
                               value="{{ old('company_name') }}"
                               class="mt-1.5 w-full rounded-lg border border-sand-300 px-3 py-2.5 text-sm text-ink focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500">
                        <p class="mt-1.5 text-xs text-ink-soft">Your company profile starts as pending review. You can complete it after signing in.</p>
                        @error('company_name')<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-ink">Email address</label>
                        <input id="email" name="email" type="email" required autocomplete="email" value="{{ old('email') }}"
                               class="mt-1.5 w-full rounded-lg border border-sand-300 px-3 py-2.5 text-sm text-ink focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500">
                        @error('email')<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-ink">Password</label>
                        <input id="password" name="password" type="password" required autocomplete="new-password"
                               class="mt-1.5 w-full rounded-lg border border-sand-300 px-3 py-2.5 text-sm text-ink focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500">
                        @error('password')<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-ink">Confirm password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                               class="mt-1.5 w-full rounded-lg border border-sand-300 px-3 py-2.5 text-sm text-ink focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500">
                    </div>

                    <button type="submit"
                            class="w-full rounded-lg bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">
                        Create account
                    </button>
                </form>
            </div>

            <p class="mt-6 text-center text-sm text-ink-soft">
                Already have an account?
                <a href="{{ route('login') }}" class="font-semibold text-forest-700 hover:text-forest-800">Log in</a>
            </p>
        </div>
    </div>
</x-layouts.app>
