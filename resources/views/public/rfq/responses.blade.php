@php
    use App\Enums\QuoteStatus;

    // Screen 1 — every response received for one RFQ.
    //
    // Everything on this page is a real stored value. The one derived badge is
    // "Lowest total", which is computed from the submitted totals and labelled
    // as exactly that; there are no ratings, scores or savings estimates that
    // are not backed by a column.
    $sorts = [
        'total' => __('messages.rfq_wizard.sort_total'),
        'lead_time' => __('messages.rfq_wizard.sort_lead_time'),
        'validity' => __('messages.rfq_wizard.sort_validity'),
        'newest' => __('messages.rfq_wizard.sort_newest'),
    ];

    $deadline = $rfq->deadline;
@endphp

<x-layouts.app
    :title="__('messages.rfq_wizard.responses_title', ['ref' => $rfq->reference_code])"
    :description="__('messages.rfq_wizard.responses_meta')"
    noindex
    :breadcrumbs="[
        ['label' => __('messages.common.home'), 'url' => route('home')],
        ['label' => __('messages.rfq_wizard.breadcrumb_rfq_center'), 'url' => route('rfq.create')],
        ['label' => $rfq->reference_code, 'url' => url()->current()],
        ['label' => __('messages.rfq_wizard.responses_received'), 'url' => url()->current()],
    ]">

    <div class="mx-auto max-w-6xl px-4 py-8 sm:py-12">

        @if (session('quote_notice'))
            <div role="status"
                 class="mb-6 flex items-start gap-3 rounded-2xl border border-forest-200 bg-forest-50 px-4 py-3 text-[1.0625rem] text-forest-900 dark:border-forest-900 dark:bg-forest-950 dark:text-forest-200">
                <x-heroicon-o-check-circle class="mt-0.5 h-5 w-5 shrink-0" />
                <p>{{ session('quote_notice') }}</p>
            </div>
        @endif

        <header class="rounded-2xl border border-sand-200 bg-white px-5 py-6 dark:border-[#2c2a24] dark:bg-[#1f1d18] sm:px-7">
            <p class="text-[0.875rem] font-semibold uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">
{{ __('messages.rfq_wizard.request_label') }} {{ $rfq->reference_code }}
            </p>
            <h1 class="mt-1 font-display text-2xl font-semibold text-forest-950 dark:text-sand-100 sm:text-3xl">
                {{ $rfq->title ?: __('messages.rfq_wizard.responses_heading_fallback') }}
            </h1>

            <dl class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.responses_received') }}</dt>
                    <dd class="mt-0.5 text-[1.125rem] font-bold text-forest-800 dark:text-forest-300">{{ $quotes->count() }}</dd>
                </div>
                <div>
                    <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.suppliers_contacted') }}</dt>
                    <dd class="mt-0.5 text-[1.125rem] font-bold text-ink dark:text-[#e4ddcf]">{{ $rfq->routings()->count() }}</dd>
                </div>
                <div>
                    <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.response_deadline_label') }}</dt>
                    <dd class="mt-0.5 text-[1.125rem] font-semibold text-ink dark:text-[#e4ddcf]">
                        {{ $deadline?->isoFormat('D MMM YYYY') ?? __('messages.rfq_wizard.not_set_label') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.request_status') }}</dt>
                    <dd class="mt-0.5">
                        <span class="inline-flex items-center rounded-full bg-forest-50 px-2.5 py-1 text-[0.9375rem] font-semibold text-forest-800 dark:bg-forest-950 dark:text-forest-300">
                            {{ $rfq->status->label() }}
                        </span>
                    </dd>
                </div>
            </dl>
        </header>

        <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">

            <div>
                @if ($quotes->isNotEmpty())
                    <form method="GET" action="{{ url()->current() }}"
                          class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        @foreach (request()->query() as $key => $value)
                            @if ($key !== 'sort' && is_string($value))
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endif
                        @endforeach

                        <p class="text-[1.0625rem] text-ink-soft dark:text-[#b3ab9b]">
                            {{ trans_choice('messages.rfq_wizard.showing_quotes', $quotes->count(), ['count' => $quotes->count()]) }}
                        </p>

                        <div class="flex items-center gap-2">
                            <label for="sort" class="text-[1.0625rem] font-medium text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.rfq_wizard.sort_by') }}</label>
                            <select id="sort" name="sort" onchange="this.form.submit()"
                                    class="rounded-lg border border-sand-300 bg-white px-3 py-2 text-[1.0625rem] text-ink focus:border-forest-500 focus:outline-none focus:ring-1 focus:ring-forest-500 dark:border-[#3a372f] dark:bg-[#26241e] dark:text-[#e4ddcf]">
                                @foreach ($sorts as $key => $label)
                                    <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <noscript>
                                <button type="submit" class="rounded-lg bg-forest-700 px-3 py-2 text-[1.0625rem] font-semibold text-white">{{ __('messages.rfq_wizard.apply') }}</button>
                            </noscript>
                        </div>
                    </form>

                    <ul class="space-y-4">
                        @foreach ($quotes as $quote)
                            @php
                                $units = $quote->items->pluck('unit')->unique();
                                $singleUnit = $units->count() === 1 ? $units->first() : null;
                                $qty = (float) $quote->items->sum(fn ($i) => (float) $i->quantity);
                                $perUnit = $singleUnit && $qty > 0 ? (float) $quote->subtotal_amount / $qty : null;
                                $isLowest = $lowestTotal !== null && $quote->isActionable()
                                    && abs((float) $quote->total_amount - $lowestTotal) < 0.005;
                            @endphp
                            <li class="rounded-2xl border border-sand-200 bg-white p-5 transition hover:border-forest-300 dark:border-[#2c2a24] dark:bg-[#1f1d18] dark:hover:border-forest-800">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">
                                                <a href="{{ $access->link(request(), 'quote', $rfq, $quote) }}"
                                                   class="hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500">
                                                    {{ $quote->company->name }}
                                                </a>
                                            </h2>
                                            <span class="inline-flex items-center rounded-full bg-sand-100 px-2 py-0.5 text-[0.875rem] font-semibold text-ink-soft dark:bg-[#2c2a24] dark:text-[#b3ab9b]">
                                                {{ $quote->status->label() }}
                                            </span>
                                            @if ($isLowest)
                                                <span class="inline-flex items-center rounded-full bg-forest-50 px-2 py-0.5 text-[0.875rem] font-semibold text-forest-800 dark:bg-forest-950 dark:text-forest-300">
                                                    {{ __('messages.rfq_wizard.lowest_total') }}
                                                </span>
                                            @endif
                                        </div>
                                        <p class="mt-1 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                                            {{ collect([$quote->company->city, $quote->company->country_code])->filter()->implode(', ') }}
                                            · Quote {{ $quote->reference_code }}
                                        </p>
                                        @if ($quote->company->hasRating())
                                            <p class="mt-1 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                                                {{ number_format((float) $quote->company->rating_avg, 1) }} / 5
                                                ({{ trans_choice('messages.rfq_wizard.reviews', (int) $quote->company->rating_count, ['count' => $quote->company->rating_count]) }})
                                            </p>
                                        @endif
                                    </div>

                                    <div class="text-right">
                                        <p class="font-display text-xl font-bold text-forest-800 dark:text-forest-300">
                                            {{ $quote->money($quote->total_amount) }}
                                        </p>
                                        @if ($perUnit !== null)
                                            <p class="text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">
                                                {{ __('messages.rfq_wizard.per_unit', ['currency' => $quote->currency->value, 'price' => number_format($perUnit, 2), 'unit' => $singleUnit->label()]) }}
                                            </p>
                                        @endif
                                    </div>
                                </div>

                                <dl class="mt-4 grid gap-3 border-t border-sand-200 pt-4 dark:border-[#2c2a24] sm:grid-cols-4">
                                    <div>
                                        <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.lead_time') }}</dt>
                                        <dd class="mt-0.5 text-[1.0625rem] font-semibold text-ink dark:text-[#e4ddcf]">
                                            {{ $quote->lead_time_days ? __('messages.account.days', ['count' => $quote->lead_time_days]) : '—' }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.valid_until') }}</dt>
                                        <dd class="mt-0.5 text-[1.0625rem] font-semibold text-ink dark:text-[#e4ddcf]">
                                            {{ $quote->valid_until?->isoFormat('D MMM YYYY') ?? '—' }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.incoterm') }}</dt>
                                        <dd class="mt-0.5 text-[1.0625rem] font-semibold text-ink dark:text-[#e4ddcf]">
                                            {{ $quote->incoterm?->value ?? '—' }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.line_items') }}</dt>
                                        <dd class="mt-0.5 text-[1.0625rem] font-semibold text-ink dark:text-[#e4ddcf]">{{ $quote->items->count() }}</dd>
                                    </div>
                                </dl>

                                <div class="mt-4">
                                    <a href="{{ $access->link(request(), 'quote', $rfq, $quote) }}"
                                       class="inline-flex items-center gap-2 rounded-full bg-forest-700 px-5 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                                        {{ __('messages.rfq_wizard.review_quote') }} <x-heroicon-m-arrow-right class="h-4 w-4" />
                                    </a>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="rounded-2xl border border-dashed border-sand-300 bg-white px-6 py-12 text-center dark:border-[#3a372f] dark:bg-[#1f1d18]">
                        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-sand-100 text-ink-soft dark:bg-[#2c2a24] dark:text-[#8f887b]">
                            <x-heroicon-o-inbox class="h-6 w-6" />
                        </span>
                        <h2 class="mt-4 font-display text-lg font-semibold text-forest-950 dark:text-sand-100">{{ __('messages.rfq_wizard.no_quotes_yet') }}</h2>
                        <p class="mx-auto mt-2 max-w-md text-[1.0625rem] leading-relaxed text-ink-soft dark:text-[#b3ab9b]">
                            @if ($rfq->routings()->count() > 0)
                                {{ trans_choice('messages.rfq_wizard.reached_suppliers', $rfq->routings()->count(), ['count' => $rfq->routings()->count()]) }}
                                {{ __('messages.rfq_wizard.will_email_when_responds', ['email' => $rfq->buyer_email]) }}
                            @else
                                {{ __('messages.rfq_wizard.not_sent_yet', ['email' => $rfq->buyer_email]) }}
                            @endif
                        </p>
                    </div>
                @endif
            </div>

            <aside class="space-y-4">
                <section class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                    <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.rfq_wizard.request_summary') }}</h2>
                    <dl class="mt-4 space-y-3 text-[1.0625rem]">
                        <div>
                            <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.reference') }}</dt>
                            <dd class="mt-0.5 font-semibold text-ink dark:text-[#e4ddcf]">{{ $rfq->reference_code }}</dd>
                        </div>
                        @if ($rfq->project_name)
                            <div>
                                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.project') }}</dt>
                                <dd class="mt-0.5 font-semibold text-ink dark:text-[#e4ddcf]">{{ $rfq->project_name }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.submitted') }}</dt>
                            <dd class="mt-0.5 font-semibold text-ink dark:text-[#e4ddcf]">{{ $rfq->created_at?->isoFormat('D MMM YYYY') }}</dd>
                        </div>
                        @if ($rfq->incoterm)
                            <div>
                                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.requested_incoterm') }}</dt>
                                <dd class="mt-0.5 font-semibold text-ink dark:text-[#e4ddcf]">{{ $rfq->incoterm->value }}</dd>
                            </div>
                        @endif
                    </dl>
                </section>

                @if ($rfq->items->isNotEmpty())
                    <section class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                        <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.rfq_wizard.what_you_asked_for') }}</h2>
                        <ul class="mt-3 space-y-2 text-[1.0625rem] text-ink dark:text-[#e4ddcf]">
                            @foreach ($rfq->items as $item)
                                <li>{{ $item->label() }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($accepted)
                    <section class="rounded-2xl border border-forest-200 bg-forest-50 p-5 dark:border-forest-900 dark:bg-forest-950">
                        <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.rfq_wizard.awarded') }}</h2>
                        <p class="mt-2 text-[1.0625rem] text-forest-900 dark:text-forest-200">
                            {{ __('messages.rfq_wizard.awarded_line', [
                                'company' => $accepted->company->name,
                                'amount' => $accepted->money($accepted->total_amount),
                                'date' => $accepted->decided_at?->isoFormat('D MMM YYYY'),
                            ]) }}
                        </p>
                    </section>
                @endif
            </aside>
        </div>
    </div>
</x-layouts.app>
