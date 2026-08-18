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
    :title="'Quote '.$quote->reference_code.' — '.$company->name"
    description="Supplier quote evaluation."
    noindex
    :breadcrumbs="[
        ['label' => 'Home', 'url' => route('home')],
        ['label' => $rfq->reference_code, 'url' => $access->link(request(), 'responses', $rfq)],
        ['label' => 'Responses', 'url' => $access->link(request(), 'responses', $rfq)],
        ['label' => 'Quote detail', 'url' => url()->current()],
    ]">

    <div class="mx-auto max-w-6xl px-4 py-8 sm:py-12">

        <a href="{{ $access->link(request(), 'responses', $rfq) }}"
           class="inline-flex items-center gap-2 text-[0.875rem] font-medium text-forest-700 hover:underline dark:text-forest-300">
            <x-heroicon-m-arrow-left class="h-4 w-4" /> Back to responses
        </a>

        @if ($errors->any())
            <div role="alert"
                 class="mt-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-[0.875rem] text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
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
                                <span class="inline-flex items-center rounded-full bg-sand-100 px-2.5 py-1 text-[0.6875rem] font-semibold text-ink-soft dark:bg-[#2c2a24] dark:text-[#b3ab9b]">
                                    {{ $quote->status->label() }}
                                </span>
                            </div>
                            <p class="mt-1 text-[0.875rem] text-ink-soft dark:text-[#8f887b]">
                                {{ collect([$company->city, $company->region, $company->country_code])->filter()->implode(', ') }}
                            </p>
                            <p class="mt-1 text-[0.8125rem] text-ink-soft dark:text-[#8f887b]">
                                Quote {{ $quote->reference_code }}
                                @if ($quote->submitted_at) · submitted {{ $quote->submitted_at->isoFormat('D MMM YYYY') }} @endif
                            </p>

                            <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-[0.8125rem] text-ink-soft dark:text-[#8f887b]">
                                @if ($company->on_time_delivery_percent !== null)
                                    <span>{{ $company->on_time_delivery_percent }}% on-time delivery</span>
                                @endif
                                @if ($company->hasRating())
                                    <span>{{ number_format((float) $company->rating_avg, 1) }} / 5 ({{ $company->rating_count }} reviews)</span>
                                @endif
                                @if ($company->year_founded)
                                    <span>Supplier since {{ $company->year_founded }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="flex flex-col gap-2">
                            @if ($company->slug)
                                <a href="{{ route('companies.show', $company->slug) }}"
                                   class="inline-flex items-center justify-center gap-2 rounded-full border border-sand-300 px-4 py-2 text-[0.8125rem] font-semibold text-ink transition hover:border-forest-400 dark:border-[#3a372f] dark:text-[#e4ddcf]">
                                    View supplier profile
                                </a>
                            @endif
                            @if ($company->email)
                                <a href="mailto:{{ $company->email }}?subject={{ rawurlencode('Quote '.$quote->reference_code) }}"
                                   class="inline-flex items-center justify-center gap-2 rounded-full border border-sand-300 px-4 py-2 text-[0.8125rem] font-semibold text-ink transition hover:border-forest-400 dark:border-[#3a372f] dark:text-[#e4ddcf]">
                                    Email supplier
                                </a>
                            @endif
                            @if ($company->phone)
                                <a href="tel:{{ preg_replace('/\s+/', '', $company->phone) }}"
                                   class="inline-flex items-center justify-center gap-2 rounded-full border border-sand-300 px-4 py-2 text-[0.8125rem] font-semibold text-ink transition hover:border-forest-400 dark:border-[#3a372f] dark:text-[#e4ddcf]">
                                    {{ $company->phone }}
                                </a>
                            @endif
                        </div>
                    </div>
                </section>

                <section aria-labelledby="quote-summary"
                         class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18] sm:p-7">
                    <h2 id="quote-summary" class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">Quote summary</h2>

                    <dl class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Total quote price</dt>
                            <dd class="mt-1 font-display text-xl font-bold text-forest-800 dark:text-forest-300">{{ $quote->money($quote->total_amount) }}</dd>
                        </div>
                        <div>
                            <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Lead time</dt>
                            <dd class="mt-1 text-[1.0625rem] font-semibold text-ink dark:text-[#e4ddcf]">
                                {{ $quote->lead_time_days ? $quote->lead_time_days.' days' : 'Not stated' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Payment terms</dt>
                            <dd class="mt-1 text-[0.9375rem] font-semibold text-ink dark:text-[#e4ddcf]">{{ $quote->payment_terms ?: 'Not stated' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Validity</dt>
                            <dd class="mt-1 text-[0.9375rem] font-semibold text-ink dark:text-[#e4ddcf]">
                                @if ($quote->valid_until)
                                    Until {{ $quote->valid_until->isoFormat('D MMM YYYY') }}
                                    <span class="block text-[0.75rem] font-normal text-ink-soft dark:text-[#8f887b]">
                                        {{ $quote->isExpired() ? 'Expired' : $days.' days remaining' }}
                                    </span>
                                @else
                                    Not stated
                                @endif
                            </dd>
                        </div>
                    </dl>
                </section>

                <section aria-labelledby="quote-lines"
                         class="overflow-hidden rounded-2xl border border-sand-200 bg-white dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                    <div class="border-b border-sand-200 px-5 py-4 dark:border-[#2c2a24] sm:px-7">
                        <h2 id="quote-lines" class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">Products quoted</h2>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[46rem] text-left text-[0.875rem]">
                            <thead class="bg-sand-50 text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:bg-[#26241e] dark:text-[#8f887b]">
                                <tr>
                                    <th scope="col" class="px-5 py-3 font-semibold sm:px-7">#</th>
                                    <th scope="col" class="px-3 py-3 font-semibold">Product</th>
                                    <th scope="col" class="px-3 py-3 font-semibold">Specification</th>
                                    <th scope="col" class="px-3 py-3 text-right font-semibold">Quantity</th>
                                    <th scope="col" class="px-3 py-3 font-semibold">Unit</th>
                                    <th scope="col" class="px-3 py-3 text-right font-semibold">Unit price</th>
                                    <th scope="col" class="px-5 py-3 text-right font-semibold sm:px-7">Line total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-sand-200 dark:divide-[#2c2a24]">
                                @foreach ($quote->items as $index => $item)
                                    <tr class="text-ink dark:text-[#e4ddcf]">
                                        <td class="px-5 py-3 text-ink-soft dark:text-[#8f887b] sm:px-7">{{ $index + 1 }}</td>
                                        <td class="px-3 py-3 font-medium">
                                            {{ $item->description }}
                                            @if ($item->species)
                                                <span class="block text-[0.75rem] text-ink-soft dark:text-[#8f887b]">{{ $item->species->common_name }}</span>
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
                                    <td colspan="6" class="px-3 py-2 text-right text-ink-soft dark:text-[#8f887b]">Subtotal</td>
                                    <td class="px-5 py-2 text-right tabular-nums sm:px-7">{{ $quote->money($quote->subtotal_amount) }}</td>
                                </tr>
                                @if ($quote->shipping_amount !== null)
                                    <tr>
                                        <td colspan="6" class="px-3 py-2 text-right text-ink-soft dark:text-[#8f887b]">Shipping</td>
                                        <td class="px-5 py-2 text-right tabular-nums sm:px-7">{{ $quote->money($quote->shipping_amount) }}</td>
                                    </tr>
                                @endif
                                @if ($quote->tax_amount !== null)
                                    <tr>
                                        <td colspan="6" class="px-3 py-2 text-right text-ink-soft dark:text-[#8f887b]">Tax</td>
                                        <td class="px-5 py-2 text-right tabular-nums sm:px-7">{{ $quote->money($quote->tax_amount) }}</td>
                                    </tr>
                                @endif
                                <tr class="bg-sand-50 dark:bg-[#26241e]">
                                    <td colspan="6" class="px-3 py-3 text-right font-display font-bold text-forest-950 dark:text-sand-100">Total quote price</td>
                                    <td class="px-5 py-3 text-right font-display text-lg font-bold tabular-nums text-forest-800 dark:text-forest-300 sm:px-7">
                                        {{ $quote->money($quote->total_amount) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                <section class="grid gap-6 md:grid-cols-2">
                    <div class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                        <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">Terms</h2>
                        <dl class="mt-3 space-y-3 text-[0.875rem]">
                            <div>
                                <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Incoterm</dt>
                                <dd class="mt-0.5 text-ink dark:text-[#e4ddcf]">{{ $quote->incoterm?->label() ?? 'Not stated' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Payment terms</dt>
                                <dd class="mt-0.5 text-ink dark:text-[#e4ddcf]">{{ $quote->payment_terms ?: 'Not stated' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Currency</dt>
                                <dd class="mt-0.5 text-ink dark:text-[#e4ddcf]">{{ $quote->currency->label() }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                        <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">Supplier notes</h2>
                        <p class="mt-3 whitespace-pre-line text-[0.875rem] leading-relaxed text-ink-soft dark:text-[#b3ab9b]">
                            {{ $quote->notes ?: 'The supplier did not add any notes to this quote.' }}
                        </p>
                    </div>
                </section>
            </div>

            <aside class="space-y-4">
                <section class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                    <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">Request summary</h2>
                    <dl class="mt-4 space-y-3 text-[0.875rem]">
                        <div>
                            <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Reference</dt>
                            <dd class="mt-0.5 font-semibold text-ink dark:text-[#e4ddcf]">{{ $rfq->reference_code }}</dd>
                        </div>
                        @if ($rfq->title)
                            <div>
                                <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Title</dt>
                                <dd class="mt-0.5 font-semibold text-ink dark:text-[#e4ddcf]">{{ $rfq->title }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Responses received</dt>
                            <dd class="mt-0.5 font-semibold text-ink dark:text-[#e4ddcf]">{{ $siblingCount }}</dd>
                        </div>
                        <div>
                            <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Total quantity quoted</dt>
                            <dd class="mt-0.5 font-semibold text-ink dark:text-[#e4ddcf]">{{ $quote->totalQuantity() }}</dd>
                        </div>
                    </dl>
                </section>

                <section aria-labelledby="quote-actions"
                         class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                    <h2 id="quote-actions" class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">Your decision</h2>

                    @if ($actionable)
                        <form method="POST" action="{{ $access->link(request(), 'accept', $rfq, $quote) }}" class="mt-4">
                            @csrf
                            <label class="flex items-start gap-2 text-[0.8125rem] leading-relaxed text-ink-soft dark:text-[#b3ab9b]">
                                <input type="checkbox" name="confirm" value="1" required
                                       class="mt-0.5 h-4 w-4 rounded border-sand-300 text-forest-700 focus:ring-forest-500 dark:border-[#3a372f]">
                                <span>
                                    I confirm I want to accept this quote at {{ $quote->money($quote->total_amount) }}.
                                    Every other quote on this request will be declined.
                                </span>
                            </label>
                            <button type="submit"
                                    class="mt-3 flex w-full items-center justify-center gap-2 rounded-full bg-forest-700 px-5 py-3 text-[0.875rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                                <x-heroicon-m-check-circle class="h-5 w-5" /> Accept this quote
                            </button>
                        </form>

                        <form method="POST" action="{{ $access->link(request(), 'decline', $rfq, $quote) }}"
                              class="mt-6 border-t border-sand-200 pt-5 dark:border-[#2c2a24]">
                            @csrf
                            <label for="reason" class="block text-[0.8125rem] font-medium text-ink dark:text-[#e4ddcf]">
                                Reason for declining <span class="text-red-600">*</span>
                            </label>
                            <textarea id="reason" name="reason" rows="3" required minlength="5" maxlength="500"
                                      placeholder="e.g. price above budget, lead time too long"
                                      class="mt-2 w-full rounded-lg border border-sand-300 bg-white px-3 py-2 text-[0.875rem] text-ink focus:border-forest-500 focus:outline-none focus:ring-1 focus:ring-forest-500 dark:border-[#3a372f] dark:bg-[#26241e] dark:text-[#e4ddcf]">{{ old('reason') }}</textarea>
                            <p class="mt-1 text-[0.75rem] text-ink-soft dark:text-[#8f887b]">Shared with the supplier so they can improve future offers.</p>
                            <button type="submit"
                                    class="mt-3 flex w-full items-center justify-center gap-2 rounded-full border border-sand-300 px-5 py-3 text-[0.875rem] font-semibold text-ink transition hover:border-red-400 hover:text-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 dark:border-[#3a372f] dark:text-[#e4ddcf]">
                                <x-heroicon-m-x-mark class="h-5 w-5" /> Decline this quote
                            </button>
                        </form>
                    @else
                        <p class="mt-3 text-[0.875rem] leading-relaxed text-ink-soft dark:text-[#b3ab9b]">
                            @switch($quote->status->value)
                                @case('accepted')
                                    You accepted this quote on {{ $quote->decided_at?->isoFormat('D MMM YYYY') }}.
                                    @break
                                @case('declined')
                                    You declined this quote on {{ $quote->decided_at?->isoFormat('D MMM YYYY') }}.
                                    @if ($quote->decline_reason)
                                        <span class="mt-2 block italic">“{{ $quote->decline_reason }}”</span>
                                    @endif
                                    @break
                                @default
                                    This quote lapsed on {{ $quote->valid_until?->isoFormat('D MMM YYYY') }} and can no longer be accepted.
                            @endswitch
                        </p>
                    @endif
                </section>

                <p class="px-1 text-[0.75rem] leading-relaxed text-ink-soft dark:text-[#8f887b]">
                    Accepting a quote records your decision on this platform. It is not a contract of sale —
                    conduct your own due diligence before any transaction.
                </p>
            </aside>
        </div>
    </div>
</x-layouts.app>
