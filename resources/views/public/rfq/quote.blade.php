@php
    // Screen 2 — one supplier quote in full, plus the award decision.
    //
    // Accept and Decline are POSTs with CSRF; Accept additionally requires an
    // explicit confirmation checkbox and Decline requires a reason. There is no
    // GET route that changes state.
    $company = $quote->company;
    $actionable = $quote->isActionable();
    $days = $quote->daysRemaining();
@endphp

<x-layouts.app
    :title="__('messages.rfq_wizard.quote_title', ['ref' => $quote->reference_code, 'company' => $company->name])"
    :description="__('messages.rfq_wizard.quote_meta')"
    noindex
    :breadcrumbs="[
        ['label' => __('messages.common.home'), 'url' => route('home')],
        ['label' => $rfq->reference_code, 'url' => $access->link(request(), 'responses', $rfq)],
        ['label' => __('messages.rfq_wizard.responses_received'), 'url' => $access->link(request(), 'responses', $rfq)],
        ['label' => __('messages.rfq_wizard.review_quote'), 'url' => url()->current()],
    ]">

    <div class="mx-auto max-w-6xl px-4 py-8 sm:py-12">

        <a href="{{ $access->link(request(), 'responses', $rfq) }}"
           class="inline-flex items-center gap-2 text-[1.0625rem] font-medium text-forest-700 hover:underline dark:text-forest-300">
            <x-heroicon-m-arrow-left class="h-4 w-4" /> {{ __('messages.rfq_wizard.back_to_responses') }}
        </a>

        @if ($errors->any())
            <div role="alert"
                 class="mt-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-[1.0625rem] text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                <ul class="space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-4 grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">

            <div class="space-y-6">

                <section class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18] sm:p-7">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="font-display text-2xl font-semibold text-forest-950 dark:text-sand-100">{{ $company->name }}</h1>
                                <span class="inline-flex items-center rounded-full bg-sand-100 px-2.5 py-1 text-[0.875rem] font-semibold text-ink-soft dark:bg-[#2c2a24] dark:text-[#b3ab9b]">
                                    {{ $quote->status->label() }}
                                </span>
                            </div>
                            <p class="mt-1 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                                {{ collect([$company->city, $company->region, $company->country_code])->filter()->implode(', ') }}
                            </p>
                            <p class="mt-1 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                                {{ __('messages.rfq_wizard.quote_ref', ['ref' => $quote->reference_code]) }}
                                @if ($quote->submitted_at) · {{ __('messages.rfq_wizard.submitted_on', ['date' => $quote->submitted_at->isoFormat('D MMM YYYY')]) }} @endif
                            </p>

                            <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                                @if ($company->on_time_delivery_percent !== null)
                                    <span>{{ __('messages.rfq_wizard.on_time_delivery', ['percent' => $company->on_time_delivery_percent]) }}</span>
                                @endif
                                @if ($company->hasRating())
                                    <span>{{ __('messages.rfq_wizard.rating_reviews', ['rating' => number_format((float) $company->rating_avg, 1), 'count' => $company->rating_count]) }}</span>
                                @endif
                                @if ($company->year_founded)
                                    <span>{{ __('messages.rfq_wizard.supplier_since', ['year' => $company->year_founded]) }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="flex flex-col gap-2">
                            @if ($company->slug)
                                <a href="{{ route('companies.show', $company->slug) }}"
                                   class="inline-flex items-center justify-center gap-2 rounded-full border border-sand-300 px-4 py-2 text-[1.0625rem] font-semibold text-ink transition hover:border-forest-400 dark:border-[#3a372f] dark:text-[#e4ddcf]">
                                    {{ __('messages.rfq_wizard.view_supplier_profile') }}
                                </a>
                            @endif
                            @if ($company->email)
                                <a href="mailto:{{ $company->email }}?subject={{ rawurlencode(__('messages.rfq_wizard.quote_ref', ['ref' => $quote->reference_code])) }}"
                                   class="inline-flex items-center justify-center gap-2 rounded-full border border-sand-300 px-4 py-2 text-[1.0625rem] font-semibold text-ink transition hover:border-forest-400 dark:border-[#3a372f] dark:text-[#e4ddcf]">
                                    {{ __('messages.rfq_wizard.email_supplier') }}
                                </a>
                            @endif
                            @if ($company->phone)
                                <a href="tel:{{ preg_replace('/\s+/', '', $company->phone) }}"
                                   class="inline-flex items-center justify-center gap-2 rounded-full border border-sand-300 px-4 py-2 text-[1.0625rem] font-semibold text-ink transition hover:border-forest-400 dark:border-[#3a372f] dark:text-[#e4ddcf]">
                                    {{ $company->phone }}
                                </a>
                            @endif
                        </div>
                    </div>
                </section>

                <section aria-labelledby="quote-summary"
                         class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18] sm:p-7">
                    <h2 id="quote-summary" class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.rfq_wizard.quote_summary') }}</h2>

                    <dl class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.total_quote_price') }}</dt>
                            <dd class="mt-1 font-display text-xl font-bold text-forest-800 dark:text-forest-300">{{ $quote->money($quote->total_amount) }}</dd>
                        </div>
                        <div>
                            <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.lead_time') }}</dt>
                            <dd class="mt-1 text-[1.0625rem] font-semibold text-ink dark:text-[#e4ddcf]">
                                {{ $quote->lead_time_days ? __('messages.account.days', ['count' => $quote->lead_time_days]) : __('messages.rfq_wizard.not_stated') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.payment_terms') }}</dt>
                            <dd class="mt-1 text-[1.125rem] font-semibold text-ink dark:text-[#e4ddcf]">{{ $quote->payment_terms ?: __('messages.rfq_wizard.not_stated') }}</dd>
                        </div>
                        <div>
                            <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.validity') }}</dt>
                            <dd class="mt-1 text-[1.125rem] font-semibold text-ink dark:text-[#e4ddcf]">
                                @if ($quote->valid_until)
                                    {{ __('messages.rfq_wizard.until_date', ['date' => $quote->valid_until->isoFormat('D MMM YYYY')]) }}
                                    <span class="block text-[0.9375rem] font-normal text-ink-soft dark:text-[#8f887b]">
                                        {{ $quote->isExpired() ? __('messages.account.expired') : __('messages.rfq_wizard.days_remaining', ['count' => $days]) }}
                                    </span>
                                @else
                                    {{ __('messages.rfq_wizard.not_stated') }}
                                @endif
                            </dd>
                        </div>
                    </dl>
                </section>

                <section aria-labelledby="quote-lines"
                         class="overflow-hidden rounded-2xl border border-sand-200 bg-white dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                    <div class="border-b border-sand-200 px-5 py-4 dark:border-[#2c2a24] sm:px-7">
                        <h2 id="quote-lines" class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.rfq_wizard.products_quoted') }}</h2>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[46rem] text-left text-[1.0625rem]">
                            <thead class="bg-sand-50 text-[0.875rem] uppercase tracking-wide text-ink-soft dark:bg-[#26241e] dark:text-[#8f887b]">
                                <tr>
                                    <th scope="col" class="px-5 py-3 font-semibold sm:px-7">{{ __('messages.rfq_wizard.col_hash') }}</th>
                                    <th scope="col" class="px-3 py-3 font-semibold">{{ __('messages.rfq_wizard.col_product') }}</th>
                                    <th scope="col" class="px-3 py-3 font-semibold">{{ __('messages.rfq_wizard.col_specification') }}</th>
                                    <th scope="col" class="px-3 py-3 text-right font-semibold">{{ __('messages.rfq_wizard.col_quantity') }}</th>
                                    <th scope="col" class="px-3 py-3 font-semibold">{{ __('messages.rfq_wizard.col_unit') }}</th>
                                    <th scope="col" class="px-3 py-3 text-right font-semibold">{{ __('messages.rfq_wizard.col_unit_price') }}</th>
                                    <th scope="col" class="px-5 py-3 text-right font-semibold sm:px-7">{{ __('messages.rfq_wizard.col_line_total') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-sand-200 dark:divide-[#2c2a24]">
                                @foreach ($quote->items as $index => $item)
                                    <tr class="text-ink dark:text-[#e4ddcf]">
                                        <td class="px-5 py-3 text-ink-soft dark:text-[#8f887b] sm:px-7">{{ $index + 1 }}</td>
                                        <td class="px-3 py-3 font-medium">
                                            {{ $item->description }}
                                            @if ($item->species)
                                                <span class="block text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">{{ $item->species->common_name }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 text-ink-soft dark:text-[#b3ab9b]">{{ $item->specification() ?: '—' }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ','), '0'), '.') }}</td>
                                        <td class="px-3 py-3">{{ $item->unit->label() }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums">{{ number_format((float) $item->unit_price, 2) }}</td>
                                        <td class="px-5 py-3 text-right font-semibold tabular-nums sm:px-7">{{ number_format((float) $item->line_total, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t border-sand-200 text-ink dark:border-[#2c2a24] dark:text-[#e4ddcf]">
                                <tr>
                                    <td colspan="6" class="px-3 py-2 text-right text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.subtotal') }}</td>
                                    <td class="px-5 py-2 text-right tabular-nums sm:px-7">{{ $quote->money($quote->subtotal_amount) }}</td>
                                </tr>
                                @if ($quote->shipping_amount !== null)
                                    <tr>
                                        <td colspan="6" class="px-3 py-2 text-right text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.shipping') }}</td>
                                        <td class="px-5 py-2 text-right tabular-nums sm:px-7">{{ $quote->money($quote->shipping_amount) }}</td>
                                    </tr>
                                @endif
                                @if ($quote->tax_amount !== null)
                                    <tr>
                                        <td colspan="6" class="px-3 py-2 text-right text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.tax') }}</td>
                                        <td class="px-5 py-2 text-right tabular-nums sm:px-7">{{ $quote->money($quote->tax_amount) }}</td>
                                    </tr>
                                @endif
                                <tr class="bg-sand-50 dark:bg-[#26241e]">
                                    <td colspan="6" class="px-3 py-3 text-right font-display font-bold text-forest-950 dark:text-sand-100">{{ __('messages.rfq_wizard.total_quote_price') }}</td>
                                    <td class="px-5 py-3 text-right font-display text-lg font-bold tabular-nums text-forest-800 dark:text-forest-300 sm:px-7">
                                        {{ $quote->money($quote->total_amount) }}
                                    </td>
                                </tr>
                                @if (($commissionPreview['rule_id'] ?? null) !== null)
                                    <tr>
                                        <td colspan="6" class="px-3 py-2 text-right text-ink-soft dark:text-[#8f887b]">
                                            {{ __('messages.rfq_wizard.marketplace_commission', ['rate' => rtrim(rtrim(number_format(((float) $commissionPreview['rate']) * 100, 2), '0'), '.')]) }}
                                        </td>
                                        <td class="px-5 py-2 text-right tabular-nums sm:px-7">{{ $quote->money($commissionPreview['amount']) }}</td>
                                    </tr>
                                @endif
                            </tfoot>
                        </table>
                    </div>
                </section>

                <section class="grid gap-6 md:grid-cols-2">
                    <div class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                        <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.rfq_wizard.terms') }}</h2>
                        <dl class="mt-3 space-y-3 text-[1.0625rem]">
                            <div>
                                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.incoterm') }}</dt>
                                <dd class="mt-0.5 text-ink dark:text-[#e4ddcf]">{{ $quote->incoterm?->label() ?? __('messages.rfq_wizard.not_stated') }}</dd>
                            </div>
                            <div>
                                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.payment_terms') }}</dt>
                                <dd class="mt-0.5 text-ink dark:text-[#e4ddcf]">{{ $quote->payment_terms ?: __('messages.rfq_wizard.not_stated') }}</dd>
                            </div>
                            <div>
                                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.currency') }}</dt>
                                <dd class="mt-0.5 text-ink dark:text-[#e4ddcf]">{{ $quote->currency->label() }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                        <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.rfq_wizard.supplier_notes') }}</h2>
                        <p class="mt-3 whitespace-pre-line text-[1.0625rem] leading-relaxed text-ink-soft dark:text-[#b3ab9b]">
                            {{ $quote->notes ?: __('messages.rfq_wizard.no_supplier_notes') }}
                        </p>
                    </div>
                </section>
            </div>

            <aside class="space-y-4">
                <section class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                    <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.rfq_wizard.request_summary') }}</h2>
                    <dl class="mt-4 space-y-3 text-[1.0625rem]">
                        <div>
                            <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.reference') }}</dt>
                            <dd class="mt-0.5 font-semibold text-ink dark:text-[#e4ddcf]">{{ $rfq->reference_code }}</dd>
                        </div>
                        @if ($rfq->title)
                            <div>
                                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.rfq_title') }}</dt>
                                <dd class="mt-0.5 font-semibold text-ink dark:text-[#e4ddcf]">{{ $rfq->title }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.responses_received_label') }}</dt>
                            <dd class="mt-0.5 font-semibold text-ink dark:text-[#e4ddcf]">{{ $siblingCount }}</dd>
                        </div>
                        <div>
                            <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.total_quantity_quoted') }}</dt>
                            <dd class="mt-0.5 font-semibold text-ink dark:text-[#e4ddcf]">{{ $quote->totalQuantity() }}</dd>
                        </div>
                    </dl>
                </section>

                <section aria-labelledby="quote-actions"
                         class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                    <h2 id="quote-actions" class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.rfq_wizard.your_decision') }}</h2>

                    @if ($actionable)
                        <form method="POST" action="{{ $access->link(request(), 'accept', $rfq, $quote) }}" class="mt-4">
                            @csrf
                            <label class="flex items-start gap-2 text-[1.0625rem] leading-relaxed text-ink-soft dark:text-[#b3ab9b]">
                                <input type="checkbox" name="confirm" value="1" required
                                       class="mt-0.5 h-4 w-4 rounded border-sand-300 text-forest-700 focus:ring-forest-500 dark:border-[#3a372f]">
                                <span>{{ __('messages.rfq_wizard.accept_confirm', ['amount' => $quote->money($quote->total_amount)]) }}</span>
                            </label>
                            <button type="submit"
                                    class="mt-3 flex w-full items-center justify-center gap-2 rounded-full bg-forest-700 px-5 py-3 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                                <x-heroicon-m-check-circle class="h-5 w-5" /> {{ __('messages.rfq_wizard.accept_this_quote') }}
                            </button>
                        </form>

                        <form method="POST" action="{{ $access->link(request(), 'decline', $rfq, $quote) }}"
                              class="mt-6 border-t border-sand-200 pt-5 dark:border-[#2c2a24]">
                            @csrf
                            <label for="reason" class="block text-[1.0625rem] font-medium text-ink dark:text-[#e4ddcf]">
                                {{ __('messages.rfq_wizard.reason_for_declining') }} <span class="text-red-600">*</span>
                            </label>
                            <textarea id="reason" name="reason" rows="3" required minlength="5" maxlength="500"
                                      placeholder="{{ __('messages.rfq_wizard.decline_reason_ph') }}"
                                      class="mt-2 w-full rounded-lg border border-sand-300 bg-white px-3 py-2 text-[1.0625rem] text-ink focus:border-forest-500 focus:outline-none focus:ring-1 focus:ring-forest-500 dark:border-[#3a372f] dark:bg-[#26241e] dark:text-[#e4ddcf]">{{ old('reason') }}</textarea>
                            <p class="mt-1 text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">{{ __('messages.rfq_wizard.decline_reason_help') }}</p>
                            <button type="submit"
                                    class="mt-3 flex w-full items-center justify-center gap-2 rounded-full border border-sand-300 px-5 py-3 text-[1.0625rem] font-semibold text-ink transition hover:border-red-400 hover:text-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 dark:border-[#3a372f] dark:text-[#e4ddcf]">
                                <x-heroicon-m-x-mark class="h-5 w-5" /> {{ __('messages.rfq_wizard.decline_this_quote') }}
                            </button>
                        </form>
                    @else
                        <p class="mt-3 text-[1.0625rem] leading-relaxed text-ink-soft dark:text-[#b3ab9b]">
                            @switch($quote->status->value)
                                @case('accepted')
                                    {{ __('messages.rfq_wizard.accepted_on', ['date' => $quote->decided_at?->isoFormat('D MMM YYYY')]) }}
                                    @break
                                @case('declined')
                                    {{ __('messages.rfq_wizard.declined_on', ['date' => $quote->decided_at?->isoFormat('D MMM YYYY')]) }}
                                    @if ($quote->decline_reason)
                                        <span class="mt-2 block italic">“{{ $quote->decline_reason }}”</span>
                                    @endif
                                    @break
                                @default
                                    {{ __('messages.rfq_wizard.lapsed_on', ['date' => $quote->valid_until?->isoFormat('D MMM YYYY')]) }}
                            @endswitch
                        </p>
                    @endif
                </section>

                <p class="px-1 text-[0.9375rem] leading-relaxed text-ink-soft dark:text-[#8f887b]">
                    {{ __('messages.rfq_wizard.accept_disclaimer') }}
                </p>
            </aside>
        </div>
    </div>
</x-layouts.app>
