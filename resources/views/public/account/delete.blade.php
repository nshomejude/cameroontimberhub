<x-layouts.app
    title="Delete your account"
    description="Permanently delete your Cameroon Timber Hub account."
    :noindex="true">

    <div class="bg-sand-50 px-5 py-12 lg:py-20">
        <div class="mx-auto w-full max-w-md rounded-3xl border border-sand-200 bg-white p-7 shadow-sm lg:p-10">
            <h1 class="text-[1.5rem] font-bold tracking-tight text-forest-800">Delete your account</h1>
            <p class="mt-3 text-[1.0625rem] leading-relaxed text-ink-soft">
                This signs you out everywhere and permanently removes your name, email and phone number.
                Orders, quotes and messages you were part of stay on record for your trading partners,
                shown as “Deleted user”. If you are the only member of a company, that company and its
                products are archived. This cannot be undone.
            </p>

            <div class="mt-6">
                <x-auth.error-summary />
            </div>

            <form method="POST" action="{{ route('account.destroy') }}" class="mt-6 space-y-5" novalidate>
                @csrf

                <x-auth.field
                    name="password"
                    label="Current password"
                    type="password"
                    icon="lock-closed"
                    autocomplete="current-password"
                    :required="true" />

                <label class="flex items-start gap-3 text-[1.0625rem] text-ink-soft">
                    <input type="checkbox" name="confirm" value="1" class="mt-1 h-5 w-5 rounded border-sand-300">
                    <span>I understand my account will be permanently deleted.</span>
                </label>

                <button type="submit"
                        class="flex w-full items-center justify-center gap-2.5 rounded-xl bg-red-700 px-6 py-3.5 text-[1.125rem] font-semibold text-white transition hover:bg-red-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600">
                    Delete my account
                </button>
            </form>
        </div>
    </div>
</x-layouts.app>
