@php
    // Screen 1 — "Award Order". A read-only review of exactly what awarding
    // commits, followed by the POST that commits it.
    //
    // Awarding is `buyer.rfq.quote.accept`: POST, CSRF, and a required
    // confirmation checkbox. There is no GET route that awards anything.
    $company = $quote->company;
    $actionable = $quote->isActionable();
@endphp

<x-layouts.app
    :title="'Award order — '.$company->name"
    description="Review and confirm awarding this request."
    noindex
    :breadcrumbs="[
        ['label' => 'Home', 'url' => route('home')],
        ['label' => $rfq->reference_code, 'url' => $access->link(request(), 'responses', $rfq)],
        ['label' => 'Responses', 'url' => $access->link(request(), 'responses', $rfq)],
        ['label' => 'Award order', 'url' => url()->current()],
    ]">

    <div class="mx-auto max-w-5xl px-4 py-8 sm:py-12">

        <a href="{{ $access->link(request(), 'quote', $rfq, $quote) }}"
           class="inline-flex items-center gap-2 text-[0.875rem] font-medium text-forest-700 hover:underline dark:text-forest-300">
            <x-heroicon-m-arrow-left class="h-4 w-4" /> Back to the quote
        </a>

        @if ($errors->any())
            <div role="alert"
                 class="mt-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-[0.875rem] text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                <ul class="space-y-1">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <header class="mt-4">
            <h1 class="font-display text-2xl font-semibold text-forest-950 dark:text-sand-100 sm:text-3xl">Award this order</h1>
            <p class="mt-2 max-w-2xl text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">
                Awarding accepts {{ $company->name }}'s quote {{ $quote->reference_code }} and creates an order on
                request {{ $rfq->reference_code }}. Nothing is paid and nothing is shipped at this point — the supplier
                is notified and asked to confirm.
            </p>
        </header>

        {{-- What awarding actually does. Literal, not aspirational. --}}
        <section aria-labelledby="award-effects"
                 class="mt-6 rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18] sm:p-7">
            <h2 id="award-effects" class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">What happens when you award</h2>
            <ul class="mt-4 space-y-2.5 text-[0.9375rem] text-ink dark:text-[#e4ddcf]">
                @foreach ([
                    'This quote is accepted and can no longer be changed by the supplier.',
                    ($siblingCount > 1 ? 'The other '.($siblingCount - 1).' quote'.($siblingCount > 2 ? 's' : '').' on this request are declined automatically.' : null),
                    'Request '.$rfq->reference_code.' is closed to further quotes.',
                    'An order is created for '.$quote->money($quote->total_amount).', with the line items and terms shown below copied onto it.',
                    'A receipt is issued for the order, with a reference anyone can verify.',
                ] as $effect)
                    @if ($effect)
                        <li class="flex gap-2.5">
                            <x-heroicon-m-check-circle class="mt-0.5 h-5 w-5 shrink-0 text-forest-600 dark:text-forest-400" />
                            <span>{{ $effect }}</span>
                        </li>
                    @endif
                @endforeach
            </ul>
            <p class="mt-4 rounded-xl bg-sand-100 px-4 py-3 text-[0.875rem] text-ink-soft dark:bg-[#26241e] dark:text-[#b3ab9b]">
                Cameroon Timber Hub does not process payments. Awarding takes no money and moves no funds — you settle
                directly with the supplier on the payment terms below.
            </p>
        </section>

        <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">

            <div class="space-y-6">
                {{-- Supplier being awarded --}}
                <section class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18] sm:p-7">
                    <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">Supplier</h2>
                    <p class="mt-3 text-lg font-semibold text-ink dark:text-[#e4ddcf]">{{ $company->name }}</p>
                    <p class="mt-1 text-[0.875rem] text-ink-soft dark:text-[#8f887b]">
                        {{ collect([$company->city, $company->region, $company->country_code])->filter()->implode(', ') }}
                    </p>
                    <p class="mt-1 text-[0.8125rem] text-ink-soft dark:text-[#8f887b]">Quote {{ $quote->reference_code }}</p>
                </section>

                {{-- The exact lines that will be copied onto the order --}}
                <section aria-labelledby="award-lines"
                         class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18] sm:p-7">
                    <h2 id="award-lines" class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">Order items</h2>

                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full min-w-[42rem] text-left text-[0.875rem]">
                            <thead>
                                <tr class="border-b border-sand-200 text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:border-[#2c2a24] dark:text-[#8f887b]">
                                    <th scope="col" class="py-2 pr-3 font-semibold">#</th>
                                    <th scope="col" class="py-2 pr-3 font-semibold">Product</th>
                                    <th scope="col" class="py-2 pr-3 font-semibold">Specification</th>
                                    <th scope="col" class="py-2 pr-3 text-right font-semibold">Quantity</th>
                                    <th scope="col" class="py-2 pr-3 text-right font-semibold">Unit price</th>
                                    <th scope="col" class="py-2 text-right font-semibold">Line total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-sand-200 dark:divide-[#2c2a24]">
                                @foreach ($quote->items as $i => $item)
                                    <tr class="align-top text-ink dark:text-[#e4ddcf]">
                                        <td class="py-3 pr-3 text-ink-soft dark:text-[#8f887b]">{{ $i + 1 }}</td>
                                        <td class="py-3 pr-3 font-medium">
                                            {{ $item->description }}
                                            @if ($item->species) <span class="block text-[0.8125rem] font-normal text-ink-soft dark:text-[#8f887b]">{{ $item->species->common_name }}</span> @endif
                                        </td>
                                        <td class="py-3 pr-3 text-ink-soft dark:text-[#8f887b]">
                                            {{ collect([$item->dimensions, $item->grade])->filter()->implode(', ') ?: '—' }}
                                        </td>
                                        <td class="py-3 pr-3 text-right whitespace-nowrap">
                                            {{ rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ','), '0'), '.') }}
                                            {{ $item->unit->label() }}
                                        </td>
                                        <td class="py-3 pr-3 text-right whitespace-nowrap">{{ $quote->money($item->unit_price) }}</td>
                                        <td class="py-3 text-right font-semibold whitespace-nowrap">{{ $quote->money($item->line_total) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="text-[0.875rem]">
                                <tr>
                                    <td colspan="5" class="py-2 pr-3 text-right text-ink-soft dark:text-[#8f887b]">Subtotal</td>
                                    <td class="py-2 text-right font-semibold text-ink dark:text-[#e4ddcf]">{{ $quote->money($quote->subtotal_amount) }}</td>
                                </tr>
                                @if ($quote->shipping_amount !== null)
                                    <tr>
                                        <td colspan="5" class="py-2 pr-3 text-right text-ink-soft dark:text-[#8f887b]">Shipping</td>
                                        <td class="py-2 text-right font-semibold text-ink dark:text-[#e4ddcf]">{{ $quote->money($quote->shipping_amount) }}</td>
                                    </tr>
                                @endif
                                @if ($quote->tax_amount !== null)
                                    <tr>
                                        <td colspan="5" class="py-2 pr-3 text-right text-ink-soft dark:text-[#8f887b]">Tax</td>
                                        <td class="py-2 text-right font-semibold text-ink dark:text-[#e4ddcf]">{{ $quote->money($quote->tax_amount) }}</td>
                                    </tr>
                                @endif
                                <tr class="border-t border-sand-300 dark:border-[#3a372f]">
                                    <td colspan="5" class="py-3 pr-3 text-right font-semibold text-forest-950 dark:text-sand-100">Total order value</td>
                                    <td class="py-3 text-right font-display text-lg font-bold text-forest-800 dark:text-forest-300">{{ $quote->money($quote->total_amount) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>
            </div>

            {{-- The award action --}}
            <aside class="space-y-4">
                <section class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                    <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">Terms carried onto the order</h2>
                    <dl class="mt-4 space-y-3 text-[0.875rem]">
                        @foreach ([
                            'Payment terms' => $quote->payment_terms ?: 'Not stated',
                            'Incoterms' => $quote->incoterm?->value ?: 'Not stated',
                            'Lead time' => $quote->lead_time_days ? $quote->lead_time_days.' days after confirmation' : 'Not stated',
                            'Destination' => $rfq->shipping_port ?: ($rfq->destination_country_code ?: 'Not stated'),
                            'Quote valid until' => $quote->valid_until?->isoFormat('D MMM YYYY') ?: 'Open-ended',
                        ] as $label => $value)
                            <div>
                                <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ $label }}</dt>
                                <dd class="mt-0.5 font-medium text-ink dark:text-[#e4ddcf]">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                <section class="rounded-2xl border border-forest-200 bg-forest-50 p-5 dark:border-forest-900 dark:bg-[#1b2c22]">
                    @if ($actionable)
                        <form method="POST" action="{{ $access->link(request(), 'accept', $rfq, $quote) }}" class="space-y-4">
                            @csrf
                            <p class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">Confirm the award</p>
                            <label class="flex items-start gap-2.5 text-[0.875rem] text-ink dark:text-[#e4ddcf]">
                                <input type="checkbox" name="confirm" value="1" required
                                       class="mt-0.5 h-4 w-4 shrink-0 rounded border-sand-400 text-forest-700 focus:ring-forest-600">
                                <span>I am awarding this request to {{ $company->name }} for {{ $quote->money($quote->total_amount) }} and I understand the other quotes will be declined.</span>
                            </label>
                            <button type="submit"
                                    class="w-full rounded-full bg-forest-700 px-5 py-3 text-[0.9375rem] font-semibold text-white transition hover:bg-forest-800">
                                Award order
                            </button>
                            <p class="text-[0.75rem] text-ink-soft dark:text-[#b3ab9b]">This cannot be undone from here.</p>
                        </form>
                    @else
                        <p class="text-[0.875rem] text-ink dark:text-[#e4ddcf]">
                            This quote is {{ strtolower($quote->status->label()) }} and can no longer be awarded.
                        </p>
                    @endif
                </section>
            </aside>
        </div>
    </div>
</x-layouts.app>
