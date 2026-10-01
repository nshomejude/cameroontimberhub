{{-- Re-send the RFQ confirmation link. Posts reference + email; the answer is
     the same whether or not they match (RfqController::resend). --}}
<form method="POST" action="{{ route('rfq.resend') }}" class="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
    @csrf
    <label class="block text-sm font-medium text-ink dark:text-[#e4ddcf]">
        {{ __('messages.rfq_followup.resend_reference') }}
        <input type="text" name="reference" required maxlength="40" value="{{ old('reference', $reference ?? '') }}"
               class="mt-1 block w-full rounded-lg border border-sand-300 bg-white px-3 py-2 dark:border-[#3a352e] dark:bg-[#1f1d18]">
    </label>
    <label class="block text-sm font-medium text-ink dark:text-[#e4ddcf]">
        {{ __('messages.rfq_followup.resend_email') }}
        <input type="email" name="email" required maxlength="180" value="{{ old('email', $email ?? '') }}"
               class="mt-1 block w-full rounded-lg border border-sand-300 bg-white px-3 py-2 dark:border-[#3a352e] dark:bg-[#1f1d18]">
    </label>
    <button type="submit"
            class="inline-flex items-center justify-center rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
        {{ __('messages.rfq_followup.resend_submit') }}
    </button>
</form>
@error('reference')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
@error('email')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
