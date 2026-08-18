@php
    // Screen 3 — the printable B2B receipt document (desktop + mobile comps).
    //
    // No QR image is rendered: generating one would mean adding a package, so
    // the verification URL is printed as text instead — it carries exactly the
    // same information and survives photocopying just as well.
    //
    // The mockup's "PAID IN FULL" stamp is driven by the real payment_status
    // column, which only platform staff can set from off-platform evidence. On
    // a fresh order it reads "No payment recorded", because that is the truth.
    $company = $order->company;
    $paid = $order->payment_status === \App\Enums\OrderPaymentStatus::Paid;
@endphp

<x-layouts.app
    :title="'Receipt '.($receipt?->receipt_number ?? $order->reference_code)"
    description="Order receipt."
    noindex>

    <div class="receipt-page mx-auto max-w-5xl px-4 py-8 sm:py-12">

        <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
            <a href="{{ $access->link(request(), 'order', $rfq) }}"
               class="inline-flex items-center gap-2 text-[0.875rem] font-medium text-forest-700 hover:underline dark:text-forest-300">
                <x-heroicon-m-arrow-left class="h-4 w-4" /> Back to the order
            </a>
            <button type="button" onclick="window.print()"
                    class="inline-flex items-center gap-2 rounded-full bg-forest-700 px-5 py-2.5 text-[0.875rem] font-semibold text-white transition hover:bg-forest-800">
                <x-heroicon-m-printer class="h-4 w-4" /> Print receipt
            </button>
        </div>

        <article class="receipt-sheet mt-4 rounded-2xl border border-sand-200 bg-white p-5 text-ink dark:border-[#2c2a24] dark:bg-[#1f1d18] dark:text-[#e4ddcf] sm:p-8">

            {{-- ---------------- Masthead ---------------- --}}
            <header class="grid gap-6 border-b border-sand-200 pb-6 dark:border-[#2c2a24] md:grid-cols-[1.4fr_1fr]">
                <div class="flex items-start gap-4">
                    <img src="/brand/logo-600.png" alt="Cameroon Timber Hub" class="h-12 w-auto" width="600" height="200">
                    <div class="text-[0.8125rem] leading-relaxed text-ink-soft dark:text-[#8f887b]">
                        <p class="font-semibold text-ink dark:text-[#e4ddcf]">{{ config('app.name') }}</p>
                        <p>Marketplace for verified timber trade</p>
                        <p>Douala, Littoral Region, Cameroon</p>
                        @if ($phone = config('contact.phones.0'))<p>{{ $phone }}</p>@endif
                        @if ($email = config('contact.emails.0'))<p>{{ $email }}</p>@endif
                    </div>
                </div>

                <div class="md:text-right">
                    <h1 class="font-display text-3xl font-bold tracking-tight text-forest-800 dark:text-forest-300">RECEIPT</h1>
                    @if ($receipt)
                        <p class="mt-3 text-[0.6875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Receipt no.</p>
                        <p class="font-mono text-lg font-bold text-forest-800 dark:text-forest-300">{{ $receipt->receipt_number }}</p>
                        <p class="mt-2 inline-block rounded-lg bg-forest-800 px-3 py-1.5 text-[0.8125rem] font-medium text-white">
                            {{ $receipt->issued_at->isoFormat('D MMM YYYY, HH:mm') }}
                        </p>
                    @endif
                </div>
            </header>

            {{-- ---------------- Parties + order info ---------------- --}}
            <section class="grid gap-6 border-b border-sand-200 py-6 dark:border-[#2c2a24] md:grid-cols-3">
                <div>
                    <h2 class="text-[0.6875rem] font-semibold uppercase tracking-wider text-forest-800 dark:text-forest-300">Bill to</h2>
                    <div class="mt-2 space-y-0.5 text-[0.8125rem] leading-relaxed">
                        <p class="text-[0.9375rem] font-semibold">{{ $order->buyer_company ?: $order->buyer_name }}</p>
                        @if ($order->buyer_company)<p class="text-ink-soft dark:text-[#8f887b]">{{ $order->buyer_name }}</p>@endif
                        @if ($order->buyer_country_code)<p class="text-ink-soft dark:text-[#8f887b]">{{ $order->buyer_country_code }}</p>@endif
                        <p class="text-ink-soft dark:text-[#8f887b]">{{ $order->buyer_email }}</p>
                    </div>
                </div>

                <div>
                    <h2 class="text-[0.6875rem] font-semibold uppercase tracking-wider text-forest-800 dark:text-forest-300">Supplier</h2>
                    <div class="mt-2 space-y-0.5 text-[0.8125rem] leading-relaxed">
                        <p class="text-[0.9375rem] font-semibold">{{ $order->supplier_name }}</p>
                        <p class="text-ink-soft dark:text-[#8f887b]">
                            {{ collect([$company?->city, $company?->region, $company?->country_code])->filter()->implode(', ') ?: '—' }}
                        </p>
                        @if ($company?->email)<p class="text-ink-soft dark:text-[#8f887b]">{{ $company->email }}</p>@endif
                        @if ($company?->phone)<p class="text-ink-soft dark:text-[#8f887b]">{{ $company->phone }}</p>@endif
                    </div>
                </div>

                <div>
                    <h2 class="text-[0.6875rem] font-semibold uppercase tracking-wider text-forest-800 dark:text-forest-300">Order information</h2>
                    <dl class="mt-2 space-y-1 text-[0.8125rem]">
                        @foreach (array_filter([
                            'Order number' => $order->reference_code,
                            'Order date' => $order->awarded_at?->isoFormat('D MMM YYYY'),
                            'Order status' => $order->status->label(),
                            'Payment' => $order->payment_status->label(),
                            'Incoterms' => $order->incoterm?->value,
                            'Delivery date' => $order->expected_delivery_at?->isoFormat('D MMM YYYY'),
                        ]) as $label => $value)
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-soft dark:text-[#8f887b]">{{ $label }}</dt>
                                <dd class="text-right font-medium">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </section>

            {{-- ---------------- Items ---------------- --}}
            <section class="py-6">
                <h2 class="text-[0.6875rem] font-semibold uppercase tracking-wider text-forest-800 dark:text-forest-300">Order items</h2>

                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[38rem] text-left text-[0.8125rem]">
                        <thead>
                            <tr class="bg-forest-800 text-white">
                                <th scope="col" class="rounded-l-md px-3 py-2.5 font-semibold">#</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">Product</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">Specification</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-semibold">Qty</th>
                                <th scope="col" class="px-3 py-2.5 font-semibold">Unit</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-semibold">Unit price</th>
                                <th scope="col" class="rounded-r-md px-3 py-2.5 text-right font-semibold">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sand-200 dark:divide-[#2c2a24]">
                            @foreach ($order->items as $i => $item)
                                <tr class="align-top">
                                    <td class="px-3 py-3 text-ink-soft dark:text-[#8f887b]">{{ $i + 1 }}</td>
                                    <td class="px-3 py-3 font-medium">
                                        {{ $item->description }}
                                        @if ($item->species_name)<span class="block text-[0.75rem] font-normal text-ink-soft dark:text-[#8f887b]">{{ $item->species_name }}</span>@endif
                                    </td>
                                    <td class="px-3 py-3 text-ink-soft dark:text-[#8f887b]">{{ collect([$item->dimensions, $item->grade])->filter()->implode(', ') ?: '—' }}</td>
                                    <td class="px-3 py-3 text-right whitespace-nowrap">{{ rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ','), '0'), '.') }}</td>
                                    <td class="px-3 py-3">{{ $item->unitLabel() }}</td>
                                    <td class="px-3 py-3 text-right whitespace-nowrap">{{ $order->money($item->unit_price) }}</td>
                                    <td class="px-3 py-3 text-right font-semibold whitespace-nowrap">{{ $order->money($item->line_total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" class="px-3 py-2 text-right text-ink-soft dark:text-[#8f887b]">Subtotal</td>
                                <td class="px-3 py-2 text-right font-semibold">{{ $order->money($order->subtotal_amount) }}</td>
                            </tr>
                            @if ($order->shipping_amount !== null)
                                <tr>
                                    <td colspan="6" class="px-3 py-2 text-right text-ink-soft dark:text-[#8f887b]">Shipping</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ $order->money($order->shipping_amount) }}</td>
                                </tr>
                            @endif
                            @if ($order->tax_amount !== null)
                                <tr>
                                    <td colspan="6" class="px-3 py-2 text-right text-ink-soft dark:text-[#8f887b]">Tax</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ $order->money($order->tax_amount) }}</td>
                                </tr>
                            @endif
                            <tr class="bg-sand-100 dark:bg-[#26241e]">
                                <td colspan="6" class="px-3 py-3 text-right font-display font-bold text-forest-950 dark:text-sand-100">Total order value</td>
                                <td class="px-3 py-3 text-right font-display text-lg font-bold text-forest-800 dark:text-forest-300">{{ $order->money($order->total_amount) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            {{-- ---------------- Summaries ---------------- --}}
            <section class="grid gap-6 border-t border-sand-200 py-6 dark:border-[#2c2a24] sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <h2 class="text-[0.6875rem] font-semibold uppercase tracking-wider text-forest-800 dark:text-forest-300">Payment summary</h2>
                    <dl class="mt-2 space-y-1 text-[0.8125rem]">
                        @foreach ([
                            'Subtotal' => $order->money($order->subtotal_amount),
                            'Total' => $order->money($order->total_amount),
                            'Payment terms' => $order->payment_terms ?: 'Not stated',
                            'Recorded as paid' => $order->money($order->amount_paid),
                            'Balance' => $order->money($order->balanceDue()),
                            'Method' => $order->payment_method ?: 'Not recorded',
                        ] as $label => $value)
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-soft dark:text-[#8f887b]">{{ $label }}</dt>
                                <dd class="text-right font-medium">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p @class([
                        'mt-3 inline-block rounded-md px-3 py-1.5 text-[0.75rem] font-semibold',
                        'bg-forest-50 text-forest-800 dark:bg-[#1b2c22] dark:text-forest-300' => $paid,
                        'bg-sand-100 text-ink-soft dark:bg-[#26241e] dark:text-[#b3ab9b]' => ! $paid,
                    ])>{{ $order->payment_status->label() }}</p>
                </div>

                <div>
                    <h2 class="text-[0.6875rem] font-semibold uppercase tracking-wider text-forest-800 dark:text-forest-300">Delivery summary</h2>
                    <dl class="mt-2 space-y-1 text-[0.8125rem]">
                        @foreach ([
                            'Incoterms' => $order->incoterm?->value ?: 'Not stated',
                            'Lead time' => $order->lead_time_days ? $order->lead_time_days.' days' : 'Not stated',
                            'Expected delivery' => $order->expected_delivery_at?->isoFormat('D MMM YYYY') ?? 'Not stated',
                            'Port / destination' => $order->shipping_port ?: ($order->destination_country_code ?: 'Not stated'),
                            'Shipped' => $order->shipped_at?->isoFormat('D MMM YYYY') ?? 'Not recorded',
                            'Delivered' => $order->delivered_at?->isoFormat('D MMM YYYY') ?? 'Not recorded',
                        ] as $label => $value)
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-soft dark:text-[#8f887b]">{{ $label }}</dt>
                                <dd class="text-right font-medium">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                <div>
                    <h2 class="text-[0.6875rem] font-semibold uppercase tracking-wider text-forest-800 dark:text-forest-300">Terms &amp; conditions</h2>
                    <ul class="mt-2 space-y-1.5 text-[0.8125rem] text-ink-soft dark:text-[#8f887b]">
                        <li>This document records an order placed on {{ config('app.name') }}.</li>
                        <li>It is not a proof of payment. The platform processes no payments; buyer and supplier settle directly.</li>
                        <li>The figures above are those of the quote accepted on {{ $order->awarded_at?->isoFormat('D MMM YYYY') }} and do not change.</li>
                        <li>Its authenticity can be checked by anyone at the address in the verification panel.</li>
                    </ul>
                </div>
            </section>

            {{-- ---------------- Verification ---------------- --}}
            @if ($receipt)
                <section class="rounded-xl border border-forest-200 bg-forest-50 p-5 dark:border-forest-900 dark:bg-[#1b2c22]">
                    <h2 class="flex items-center gap-2 text-[0.8125rem] font-semibold uppercase tracking-wider text-forest-800 dark:text-forest-300">
                        <x-heroicon-m-shield-check class="h-4 w-4" /> Verify this receipt
                    </h2>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        <dl class="space-y-1 text-[0.8125rem]">
                            @foreach ([
                                'Receipt number' => $receipt->receipt_number,
                                'Order number' => $order->reference_code,
                                'Date issued' => $receipt->issued_at->isoFormat('D MMM YYYY, HH:mm'),
                                'Status' => $receipt->verificationStatus(),
                            ] as $label => $value)
                                <div class="flex justify-between gap-3">
                                    <dt class="text-ink-soft dark:text-[#8f887b]">{{ $label }}</dt>
                                    <dd class="text-right font-semibold">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                        <div class="text-[0.8125rem] text-ink-soft dark:text-[#8f887b]">
                            <p>Open the verification link below, or go to {{ route('receipts.verify') }} and enter the receipt number.</p>
                            <p class="mt-2 break-all font-mono text-[0.75rem] text-forest-800 dark:text-forest-300">{{ $receipt->verificationUrl() }}</p>
                        </div>
                    </div>
                </section>
            @endif

            <footer class="mt-6 border-t border-sand-200 pt-4 text-center text-[0.75rem] text-ink-soft dark:border-[#2c2a24] dark:text-[#8f887b]">
                <p>This is a system-generated document and does not require a physical signature.</p>
                <p>Generated on {{ now()->isoFormat('D MMM YYYY, HH:mm') }}.</p>
            </footer>
        </article>
    </div>
</x-layouts.app>
