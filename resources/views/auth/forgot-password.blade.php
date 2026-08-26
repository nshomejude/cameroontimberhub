<x-layouts.app
    title="Forgot Password"
    description="Request a password reset link for your Cameroon Timber Hub account."
    :noindex="true">

    <div class="bg-sand-50 px-5 py-12 lg:py-20">
        <div class="mx-auto w-full max-w-md rounded-3xl border border-sand-200 bg-white p-7 shadow-sm lg:p-10">
            <a href="{{ route('home') }}" class="mb-6 flex justify-center">
                <img src="/brand/logo-600.png" alt="Cameroon Timber Hub" class="h-14 w-auto" width="600" height="200">
            </a>

            <h1 class="text-center text-[1.5rem] font-bold tracking-tight text-forest-800">Forgot your password?</h1>
            <p class="mt-2 text-center text-[1.125rem] leading-relaxed text-ink-soft">
                Enter the email address on your account and we will send you a link to choose a new password.
            </p>

            @if (session('status'))
                <p role="status" class="mt-6 rounded-xl border border-forest-200 bg-forest-50 px-4 py-3 text-[1.0625rem] text-forest-800">
                    {{ session('status') }}
                </p>
            @endif

            <div class="mt-6">
                <x-auth.error-summary />
            </div>

            <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-5" novalidate>
                @csrf

                <x-auth.field
                    name="email"
                    label="Email address"
                    type="email"
                    icon="envelope"
                    placeholder="Enter your email address"
                    autocomplete="email"
                    :required="true"
                    :autofocus="true" />

                <button type="submit"
                        class="flex w-full items-center justify-center gap-2.5 rounded-xl bg-forest-800 px-6 py-3.5 text-[1.125rem] font-semibold text-white transition hover:bg-forest-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-forest-600">
                    <x-heroicon-o-paper-airplane class="h-5 w-5" aria-hidden="true" />
                    Send reset link
                </button>
            </form>

            <p class="mt-7 text-center text-[1.0625rem] text-ink-soft">
                Remembered it?
                <a href="{{ route('login') }}" class="font-semibold text-forest-700 underline-offset-2 hover:underline">Sign In</a>
            </p>
        </div>
    </div>
</x-layouts.app>
