@php
    // End state 1 — "submitted, awaiting email confirmation". Nothing has gone
    // to any supplier yet, so the copy here never claims it has.
    $reference = $submitted['reference'] ?? null;
    $email = $submitted['email'] ?? null;
    $submittedAt = ! empty($submitted['submitted_at']) ? \Illuminate\Support\Carbon::parse($submitted['submitted_at']) : null;

    $timeline = [
        ['state' => 'done', 'title' => 'Request submitted', 'body' => $reference ? 'Reference '.$reference.' was created.' : 'Your request was recorded.', 'when' => $submittedAt?->isoFormat('D MMM YYYY, HH:mm')],
        ['state' => 'current', 'title' => 'Confirm your email address', 'body' => 'Click the link we just sent. It expires in 48 hours.', 'when' => 'Waiting for you'],
        ['state' => 'todo', 'title' => 'Our team reviews the request', 'body' => 'We check the details and match them to verified exporters.', 'when' => null],
        ['state' => 'todo', 'title' => 'Exporters contact you', 'body' => 'Matched exporters reply to you directly with their quotes.', 'when' => null],
    ];
@endphp

<x-layouts.app
    title="Check your email to confirm your quote request"
    description="Your quote request has been recorded. Confirm your email address to send it into review."
    noindex
    :breadcrumbs="[
        ['label' => 'Home', 'url' => route('home')],
        ['label' => 'RFQ Center', 'url' => route('rfq.create')],
        ['label' => 'Request submitted', 'url' => route('rfq.thanks')],
    ]">

    <div class="mx-auto max-w-4xl px-4 py-10 sm:py-14">
        <x-rfq.stepper :wizard="null" :extra="['sent' => ['Confirm email', 'Link sent to you']]" active-extra="sent" />

        <section aria-labelledby="rfq-outcome"
                 class="mt-6 overflow-hidden rounded-2xl border border-forest-200 bg-gradient-to-br from-forest-50 to-sand-50 px-5 py-7 dark:border-forest-900 dark:from-forest-950 dark:to-[#1f1d18] sm:px-8">
            <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-forest-700 shadow-sm dark:bg-[#1f1d18] dark:text-forest-300">
                <x-heroicon-o-envelope class="h-6 w-6" />
            </span>
            <h1 id="rfq-outcome" tabindex="-1"
                class="mt-4 font-display text-2xl font-semibold text-forest-950 focus-visible:outline-none dark:text-sand-100 sm:text-3xl">
                One more step — confirm your email
            </h1>
            <p class="mt-2 max-w-2xl text-[0.9375rem] leading-relaxed text-ink-soft dark:text-[#b3ab9b]">
                @if ($reference)
                    {{-- Keep whitespace before @if: Blade will not compile a directive
                         that is glued to a preceding word character, which would leave
                         its @endif unmatched and break the view. --}}
                    Your request <strong class="font-semibold text-forest-800 dark:text-forest-300">{{ $reference }}</strong> has been recorded
                    @if ($email)
                        and we've sent a confirmation link to <strong class="font-semibold text-ink dark:text-[#e4ddcf]">{{ $email }}</strong>
                    @endif.
                @else
                    We've sent a confirmation link to the email address you gave us.
                @endif
                <strong class="font-semibold text-ink dark:text-[#e4ddcf]">Your request has not been sent to any exporter yet</strong> — it is only actioned once you click that link.
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('directory') }}"
                   class="inline-flex items-center gap-2 rounded-full bg-forest-700 px-6 py-3 text-[0.875rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                    Browse verified exporters <x-heroicon-m-arrow-right class="h-4 w-4" />
                </a>
                <a href="{{ route('rfq.create') }}"
                   class="inline-flex items-center gap-2 rounded-full border border-sand-300 bg-white px-6 py-3 text-[0.875rem] font-semibold text-ink transition hover:bg-sand-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2 dark:border-[#3a352e] dark:bg-[#1f1d18] dark:text-[#e4ddcf]">
                    Start another request
                </a>
            </div>
        </section>

        <x-rfq.timeline :steps="$timeline" class="mt-6" />

        <p class="mt-6 rounded-xl bg-sand-100 px-4 py-3 text-[0.8125rem] text-ink-soft dark:bg-[#26241e] dark:text-[#b3ab9b]">
            <x-heroicon-m-information-circle class="mr-1 inline h-4 w-4 align-text-bottom" />
            No confirmation email after a few minutes? Check your spam folder. The link expires 48 hours after you submitted;
            after that, simply send the request again.
        </p>
    </div>

    <script>
        (function () {
            var h = document.getElementById('rfq-outcome');
            if (h) { h.focus({ preventScroll: true }); }
        })();
    </script>
</x-layouts.app>
