<x-layouts.app
    title="Reset Password"
    description="Choose a new password for your Cameroon Timber Hub account."
    :noindex="true">

    <div class="bg-sand-50 px-5 py-12 lg:py-20">
        <div class="mx-auto w-full max-w-md rounded-3xl border border-sand-200 bg-white p-7 shadow-sm lg:p-10">
            <a href="{{ route('home') }}" class="mb-6 flex justify-center">
                <img src="/brand/logo-600.png" alt="Cameroon Timber Hub" class="h-14 w-auto" width="600" height="200">
            </a>

            <h1 class="text-center text-[1.5rem] font-bold tracking-tight text-forest-800">Choose a new password</h1>
            <p class="mt-2 text-center text-[0.9375rem] leading-relaxed text-ink-soft">
                Your new password must be at least 8 characters long.
            </p>

            <div class="mt-6">
                <x-auth.error-summary />
            </div>

            <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-5" novalidate>
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <x-auth.field
                    name="email"
                    label="Email address"
                    type="email"
                    icon="envelope"
                    :value="$email"
                    autocomplete="email"
                    :required="true" />

                <x-auth.field
                    name="password"
                    label="New password"
                    type="password"
                    icon="lock-closed"
                    placeholder="Create a strong password"
                    autocomplete="new-password"
                    :required="true"
                    :toggle="true"
                    :autofocus="true" />

                <x-auth.field
                    name="password_confirmation"
                    label="Confirm new password"
                    type="password"
                    icon="lock-closed"
                    placeholder="Re-enter your new password"
                    autocomplete="new-password"
                    :required="true"
                    :toggle="true" />

                <button type="submit"
                        class="flex w-full items-center justify-center gap-2.5 rounded-xl bg-forest-800 px-6 py-3.5 text-[0.9375rem] font-semibold text-white transition hover:bg-forest-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-forest-600">
                    <x-heroicon-o-lock-closed class="h-5 w-5" aria-hidden="true" />
                    Reset password
                </button>
            </form>
        </div>
    </div>
</x-layouts.app>
