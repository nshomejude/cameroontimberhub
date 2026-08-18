@php
    use App\Enums\RfqStatus;

    // End state 2 — the signed link was opened, the address is confirmed, and
    // the request is now in the moderation queue. Routing to exporters is an
    // admin decision that happens after approval, so this page reports the
    // request's *actual* status rather than promising it has been sent.
    $routedCount = $rfq->routings()->count();
    $approved = in_array($rfq->status, [RfqStatus::Approved, RfqStatus::Closed], true);

    $timeline = [
        ['state' => 'done', 'title' => 'Request submitted', 'body' => 'Reference '.$rfq->reference_code.' was created.', 'when' => $rfq->created_at?->isoFormat('D MMM YYYY, HH:mm')],
        ['state' => 'done', 'title' => 'Email address confirmed', 'body' => 'Your request is now actionable by our team.', 'when' => $rfq->email_verified_at?->isoFormat('D MMM YYYY, HH:mm')],
        [
            'state' => $approved ? 'done' : 'current',
            'title' => 'Reviewed by our team',
            'body' => $approved ? 'Your request passed review.' : 'We check every request before it reaches an exporter.',
            'when' => $approved ? null : 'In progress',
        ],
        [
            'state' => $routedCount > 0 ? 'done' : 'todo',
            'title' => 'Sent to matched exporters',
            'body' => $routedCount > 0
                ? 'Sent to '.$routedCount.' verified '.\Illuminate\Support\Str::plural('exporter', $routedCount).'. They will contact you directly.'
                : 'Once approved, matched exporters receive your request and contact you directly.',
            'when' => null,
        ],
    ];
@endphp

<x-layouts.app
    title="Quote request confirmed"
    description="Your email address is confirmed and your quote request is with our team."
    noindex
    :breadcrumbs="[
        ['label' => 'Home', 'url' => route('home')],
        ['label' => 'RFQ Center', 'url' => route('rfq.create')],
        ['label' => 'Request confirmed', 'url' => url()->current()],
    ]">

    <div class="mx-auto max-w-4xl px-4 py-10 sm:py-14">
        <x-rfq.stepper :wizard="null"
                       :extra="['sent' => ['Confirmed', $routedCount > 0 ? 'Sent to exporters' : 'With our team']]"
                       active-extra="sent" />

        <section aria-labelledby="rfq-outcome"
                 class="mt-6 overflow-hidden rounded-2xl border border-forest-200 bg-gradient-to-br from-forest-50 to-sand-50 px-5 py-7 dark:border-forest-900 dark:from-forest-950 dark:to-[#1f1d18] sm:px-8">
            <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-forest-700 shadow-sm dark:bg-[#1f1d18] dark:text-forest-300">
                <x-heroicon-o-check-circle class="h-6 w-6" />
            </span>
            <h1 id="rfq-outcome" tabindex="-1"
                class="mt-4 font-display text-2xl font-semibold text-forest-950 focus-visible:outline-none dark:text-sand-100 sm:text-3xl">
                Your request is confirmed
            </h1>
            <p class="mt-2 max-w-2xl text-[0.9375rem] leading-relaxed text-ink-soft dark:text-[#b3ab9b]">
                Thank you — we've confirmed your email address. Request
                <strong class="font-semibold text-forest-800 dark:text-forest-300">{{ $rfq->reference_code }}</strong>
                @if ($routedCount > 0)
                    has been sent to {{ $routedCount }} verified {{ \Illuminate\Support\Str::plural('exporter', $routedCount) }}, who will contact you directly.
                @else
                    is now with our team for review. Once it is approved we route it to the verified exporters best matched to it, and they contact you directly.
                @endif
                Quote this reference in any follow-up email.
            </p>

            <dl class="mt-6 grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl bg-white px-4 py-3 dark:bg-[#1f1d18]">
                    <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Reference</dt>
                    <dd class="mt-0.5 text-[0.9375rem] font-bold text-forest-800 dark:text-forest-300">{{ $rfq->reference_code }}</dd>
                </div>
                @if ($rfq->title)
                    <div class="rounded-xl bg-white px-4 py-3 dark:bg-[#1f1d18]">
                        <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Title</dt>
                        <dd class="mt-0.5 text-[0.9375rem] font-semibold text-ink dark:text-[#e4ddcf]">{{ $rfq->title }}</dd>
                    </div>
                @endif
                <div class="rounded-xl bg-white px-4 py-3 dark:bg-[#1f1d18]">
                    <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Products</dt>
                    <dd class="mt-0.5 text-[0.9375rem] font-semibold text-ink dark:text-[#e4ddcf]">
                        {{ trans_choice(':count product|:count products', $rfq->items->count(), ['count' => $rfq->items->count()]) }}
                    </dd>
                </div>
            </dl>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('directory') }}"
                   class="inline-flex items-center gap-2 rounded-full bg-forest-700 px-6 py-3 text-[0.875rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                    Browse verified exporters <x-heroicon-m-arrow-right class="h-4 w-4" />
                </a>
            </div>
        </section>

        @if ($rfq->items->isNotEmpty())
            <section aria-labelledby="rfq-items-heading"
                     class="mt-6 rounded-2xl border border-sand-200 bg-white dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                <div class="border-b border-sand-200 px-5 py-4 dark:border-[#2c2a24]">
                    <h2 id="rfq-items-heading" class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">What you asked for</h2>
                </div>
                <ul class="divide-y divide-sand-200 px-5 dark:divide-[#2c2a24]">
                    @foreach ($rfq->items as $item)
                        <li class="py-3 text-[0.875rem] text-ink dark:text-[#e4ddcf]">{{ $item->label() }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <x-rfq.timeline :steps="$timeline" class="mt-6" />
    </div>

    <script>
        (function () {
            var h = document.getElementById('rfq-outcome');
            if (h) { h.focus({ preventScroll: true }); }
        })();
    </script>
</x-layouts.app>
