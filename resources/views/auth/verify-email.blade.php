<x-layouts.app
    title="Verify Your Email"
    description="Confirm the email address on your Cameroon Timber Hub account."
    :noindex="true">

    <div class="bg-sand-50 px-5 py-12 lg:py-20">
        <div class="mx-auto w-full max-w-md rounded-3xl border border-sand-200 bg-white p-7 shadow-sm lg:p-10">
            <h1 class="text-center text-[1.5rem] font-bold tracking-tight text-forest-800">Verify your email address</h1>
            <p class="mt-2 text-center text-[1.125rem] leading-relaxed text-ink-soft">
                We sent a verification link to <strong>{{ auth()->user()->email }}</strong>. Click it to confirm the
                address — this lets you start conversations with suppliers and links any requests you sent before signing up.
            </p>

            @if (session('status'))
                <p role="status" class="mt-6 rounded-xl border border-forest-200 bg-forest-50 px-4 py-3 text-[1.0625rem] text-forest-800">
                    {{ session('status') }}
                </p>
            @endif

            <form method="POST" action="{{ route('verification.send') }}" class="mt-6">
                @csrf
                <button type="submit"
                        class="flex w-full items-center justify-center gap-2.5 rounded-xl bg-forest-800 px-6 py-3.5 text-[1.125rem] font-semibold text-white transition hover:bg-forest-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-forest-600">
                    Resend verification email
                </button>
            </form>
        </div>
    </div>
</x-layouts.app>
