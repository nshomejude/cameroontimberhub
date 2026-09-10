@php
    // End state 1 — "submitted, awaiting email confirmation". Nothing has gone
    // to any supplier yet, so the copy here never claims it has.
    $reference = $submitted['reference'] ?? null;
    $email = $submitted['email'] ?? null;
    $submittedAt = ! empty($submitted['submitted_at']) ? \Illuminate\Support\Carbon::parse($submitted['submitted_at']) : null;

    $timeline = [
        ['state' => 'done', 'title' => __('messages.rfq_wizard.tl_submitted_title'), 'body' => $reference ? __('messages.rfq_wizard.tl_submitted_ref', ['ref' => $reference]) : __('messages.rfq_wizard.tl_submitted_recorded'), 'when' => $submittedAt?->isoFormat('D MMM YYYY, HH:mm')],
        ['state' => 'current', 'title' => __('messages.rfq_wizard.tl_confirm_title'), 'body' => __('messages.rfq_wizard.tl_confirm_body'), 'when' => __('messages.rfq_wizard.tl_waiting')],
        ['state' => 'todo', 'title' => __('messages.rfq_wizard.tl_review_title'), 'body' => __('messages.rfq_wizard.tl_review_body'), 'when' => null],
        ['state' => 'todo', 'title' => __('messages.rfq_wizard.tl_exporters_title'), 'body' => __('messages.rfq_wizard.tl_exporters_body'), 'when' => null],
    ];
@endphp

<x-layouts.app
    :title="__('messages.rfq_wizard.thanks_title')"
    :description="__('messages.rfq_wizard.thanks_meta')"
    noindex
    :breadcrumbs="[
        ['label' => __('messages.common.home'), 'url' => route('home')],
        ['label' => __('messages.rfq_wizard.breadcrumb_rfq_center'), 'url' => route('rfq.create')],
        ['label' => __('messages.rfq_wizard.tl_submitted_title'), 'url' => route('rfq.thanks')],
    ]">

    <div class="mx-auto max-w-4xl px-4 py-10 sm:py-14">
        <x-rfq.stepper :wizard="null" :extra="['sent' => [__('messages.rfq_wizard.confirm_email_step'), __('messages.rfq_wizard.link_sent')]]" active-extra="sent" />

        <section aria-labelledby="rfq-outcome"
                 class="mt-6 overflow-hidden rounded-2xl border border-forest-200 bg-gradient-to-br from-forest-50 to-sand-50 px-5 py-7 dark:border-forest-900 dark:from-forest-950 dark:to-[#1f1d18] sm:px-8">
            <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-forest-700 shadow-sm dark:bg-[#1f1d18] dark:text-forest-300">
                <x-heroicon-o-envelope class="h-6 w-6" />
            </span>
            <h1 id="rfq-outcome" tabindex="-1"
                class="mt-4 font-display text-2xl font-semibold text-forest-950 focus-visible:outline-none dark:text-sand-100 sm:text-3xl">
                {{ __('messages.rfq_wizard.one_more_step') }}
            </h1>
            <p class="mt-2 max-w-2xl text-[1.125rem] leading-relaxed text-ink-soft dark:text-[#b3ab9b]">
                @if ($reference)
                    {{-- Keep whitespace before @if: Blade will not compile a directive
                         that is glued to a preceding word character, which would leave
                         its @endif unmatched and break the view. --}}
                    {!! __('messages.rfq_wizard.request_recorded_full', ['ref' => '<strong class="font-semibold text-forest-800 dark:text-forest-300">'.e($reference).'</strong>']) !!}
                    @if ($email)
                        {!! __('messages.rfq_wizard.and_sent_link', ['email' => '<strong class="font-semibold text-ink dark:text-[#e4ddcf]">'.e($email).'</strong>']) !!}
                    @endif.
                @else
                    {{ __('messages.rfq_wizard.sent_link_no_ref') }}
                @endif
                <strong class="font-semibold text-ink dark:text-[#e4ddcf]">{{ __('messages.rfq_wizard.not_sent_to_exporter') }}</strong> — {{ __('messages.rfq_wizard.only_actioned') }}
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('directory') }}"
                   class="inline-flex items-center gap-2 rounded-full bg-forest-700 px-6 py-3 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                    {{ __('messages.rfq_wizard.browse_verified_exporters') }} <x-heroicon-m-arrow-right class="h-4 w-4" />
                </a>
                <a href="{{ route('rfq.create') }}"
                   class="inline-flex items-center gap-2 rounded-full border border-sand-300 bg-white px-6 py-3 text-[1.0625rem] font-semibold text-ink transition hover:bg-sand-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2 dark:border-[#3a352e] dark:bg-[#1f1d18] dark:text-[#e4ddcf]">
                    {{ __('messages.rfq_wizard.start_another_request') }}
                </a>
            </div>
        </section>

        <x-rfq.timeline :steps="$timeline" class="mt-6" />

        <p class="mt-6 rounded-xl bg-sand-100 px-4 py-3 text-[1.0625rem] text-ink-soft dark:bg-[#26241e] dark:text-[#b3ab9b]">
            <x-heroicon-m-information-circle class="mr-1 inline h-4 w-4 align-text-bottom" />
            {{ __('messages.rfq_wizard.no_email_note') }}
        </p>
    </div>

    <script>
        (function () {
            var h = document.getElementById('rfq-outcome');
            if (h) { h.focus({ preventScroll: true }); }
        })();
    </script>
</x-layouts.app>
