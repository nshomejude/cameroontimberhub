{{-- Dismissible "verify your email" nudge. Shown in the buyer account layout and the exporter panel. --}}
@if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! auth()->user()->hasVerifiedEmail())
    <div x-data="{ open: true }" x-show="open" role="status" data-testid="verify-email-banner"
         class="mb-5 flex items-start justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-[1rem] text-amber-900">
        <div>
            {{ __('Please verify your email address — check your inbox for the link.') }}
            <form method="POST" action="{{ route('verification.send') }}" class="inline">
                @csrf
                <button type="submit" class="font-semibold underline underline-offset-2">{{ __('Resend link') }}</button>
            </form>
        </div>
        <button type="button" x-on:click="open = false" class="text-amber-700 hover:text-amber-900" aria-label="{{ __('Dismiss') }}">&times;</button>
    </div>
@endif
