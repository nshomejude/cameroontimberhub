@php
    // The printable proforma invoice.
    //
    // It reuses the receipt's `.receipt-sheet` print machinery verbatim (see
    // the @media print block in resources/css/app.css) rather than introducing
    // a second print stylesheet — the two documents want exactly the same
    // treatment: no site chrome, no dark theme, no page break through the
    // items table, repeated table headers.
    //
    // ⚠ Every figure here is the ORDER SNAPSHOT taken by OrderService at award
    // time. Nothing is recomputed, no tax is derived, and no invoice number is
    // minted — the document is identified by the order's own reference, because
    // an "INV-…" series would imply an invoicing ledger this platform does not
    // keep. It is not a tax invoice and not a receipt, and it says so.
    $company = $order->company;
@endphp

<x-layouts.app
    :title="'Proforma invoice '.$order->reference_code"
    description="Proforma invoice for an order placed through Cameroon Timber Hub."
    noindex>

    <div class="receipt-page mx-auto max-w-5xl px-4 py-8 sm:py-12">

        <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
            <a href="{{ route('account.messages.show', $conversation) }}"
               class="inline-flex items-center gap-2 text-[1.0625rem] font-medium text-forest-700 hover:underline dark:text-forest-300">
                <x-heroicon-m-arrow-left class="h-4 w-4" /> Back to the conversation
            </a>
            <button type="button" onclick="window.print()"
                    class="inline-flex items-center gap-2 rounded-full bg-forest-700 px-5 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800">
                <x-heroicon-m-printer class="h-4 w-4" /> Print proforma
            </button>
        </div>

        <article class="receipt-sheet mt-4 rounded-2xl border border-sand-200 bg-white p-5 text-ink dark:border-[#2c2a24] dark:bg-[#1f1d18] dark:text-[#e4ddcf] sm:p-8">

            {{-- ---------------- Masthead ---------------- --}}
            <header class="grid gap-6 border-b border-sand-200 pb-6 dark:border-[#2c2a24] md:grid-cols-[1.4fr_1fr]">
                <div class="flex items-start gap-4">
                    <img src="/brand/logo-600.png" alt="Cameroon Timber Hub" class="h-12 w-auto" width="600" height="200">
                    <div class="text-[1.0625rem] leading-relaxed text-ink-soft dark:text-[#8f887b]">
                        <p class="font-semibold text-ink dark:text-[#e4ddcf]">{{ config('app.name') }}</p>
                        <p>Marketplace for verified timber trade</p>
                        <p>Douala, Littoral Region, Cameroon</p>
                    </div>
                </div>

                <div class="md:text-right">
                    <p class="font-display text-[1.5rem] font-bold text-forest-900 dark:text-forest-300">Proforma invoice</p>
                    <p class="mt-1 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                        Order reference<br>
                        <span class="font-mono text-[1.125rem] font-semibold text-ink dark:text-[#e4ddcf]">{{ $order->reference_code }}</span>
                    </p>
                    <p class="mt-1 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                        Order date {{ $order->awarded_at?->isoFormat('D MMMM YYYY') ?? '—' }}
                    </p>
                    <p class="mt-1 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                        Status: {{ $order->status->label() }}
                    </p>
                </div>
            </header>

            {{-- ---------------- Parties ---------------- --}}
            <section class="grid gap-6 border-b border-sand-200 py-6 text-[1.0625rem] dark:border-[#2c2a24] md:grid-cols-2">
                <div>
                    <p class="text-[0.875rem] font-bold uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Supplier</p>
                    <p class="mt-1 font-semibold text-ink dark:text-[#e4ddcf]">{{ $order->supplier_name }}</p>
                    @if ($company?->city){{-- real profile values only --}}
                        <p class="text-ink-soft dark:text-[#8f887b]">{{ $company->city }}{{ $company->region ? ', '.$company->region : '' }}</p>
                    @endif
                </div>
                <div>
                    <p class="text-[0.875rem] font-bold uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Buyer</p>
                    <p class="mt-1 font-semibold text-ink dark:text-[#e4ddcf]">{{ $order->buyer_company ?: $order->buyer_name }}</p>
                    @if ($order->buyer_company && $order->buyer_name)
                        <p class="text-ink-soft dark:text-[#8f887b]">{{ $order->buyer_name }}</p>
                    @endif
                    @if ($order->buyer_country_code)
                        <p class="text-ink-soft dark:text-[#8f887b]">{{ $order->buyer_country_code }}</p>
                    @endif
                </div>
            </section>

            {{-- ---------------- Lines ---------------- --}}
            <section class="py-6">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[36rem] border-collapse text-left text-[1.0625rem]">
                        <thead>
                            <tr class="bg-forest-800 text-white">
                                <th class="px-3 py-2 font-semibold">#</th>
                                <th class="px-3 py-2 font-semibold">Description</th>
                                <th class="px-3 py-2 text-right font-semibold">Qty</th>
                                <th class="px-3 py-2 text-right font-semibold">Unit price</th>
                                <th class="px-3 py-2 text-right font-semibold">Total ({{ $order->currency->value }})</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sand-200 dark:divide-[#2c2a24]">
                            @foreach ($order->items as $index => $item)
                                <tr class="align-top">
                                    <td class="px-3 py-2.5 text-ink-soft dark:text-[#8f887b]">{{ $index + 1 }}</td>
                                    <td class="px-3 py-2.5">
                                        <span class="font-semibold text-ink dark:text-[#e4ddcf]">{{ $item->description ?: $item->species_name }}</span>
                                        @php
                                            $spec = array_filter([
                                                $item->grade ? 'Grade: '.$item->grade : null,
                                                $item->dimensions ? 'Dimensions: '.$item->dimensions : null,
                                                $item->form ? 'Form: '.$item->form : null,
                                            ]);
                                        @endphp
                                        @foreach ($spec as $line)
                                            <span class="block text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">{{ $line }}</span>
                                        @endforeach
                                    </td>
                                    <td class="px-3 py-2.5 text-right whitespace-nowrap">
                                        {{ rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.') }} {{ $item->unit }}
                                    </td>
                                    <td class="px-3 py-2.5 text-right whitespace-nowrap">{{ number_format((float) $item->unit_price, 2) }}</td>
                                    <td class="px-3 py-2.5 text-right whitespace-nowrap font-semibold">{{ number_format((float) $item->line_total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <dl class="ml-auto mt-4 max-w-xs space-y-1.5 text-[1.0625rem]">
                    <div class="flex justify-between gap-6">
                        <dt class="text-ink-soft dark:text-[#8f887b]">Subtotal</dt>
                        <dd>{{ number_format((float) $order->subtotal_amount, 2) }}</dd>
                    </div>
                    @if ((float) $order->shipping_amount > 0)
                        <div class="flex justify-between gap-6">
                            <dt class="text-ink-soft dark:text-[#8f887b]">Shipping (as quoted)</dt>
                            <dd>{{ number_format((float) $order->shipping_amount, 2) }}</dd>
                        </div>
                    @endif
                    @if ((float) $order->tax_amount > 0)
                        <div class="flex justify-between gap-6">
                            <dt class="text-ink-soft dark:text-[#8f887b]">Tax (as stated by the supplier)</dt>
                            <dd>{{ number_format((float) $order->tax_amount, 2) }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between gap-6 border-t border-sand-200 pt-1.5 dark:border-[#2c2a24]">
                        <dt class="font-semibold">Total</dt>
                        <dd class="font-display text-[1.125rem] font-bold text-forest-900 dark:text-forest-300">
                            {{ $order->currency->value }} {{ number_format((float) $order->total_amount, 2) }}
                        </dd>
                    </div>
                </dl>
            </section>

            {{-- ---------------- Terms ---------------- --}}
            @php
                $terms = array_filter([
                    'Delivery terms' => $order->incoterm?->label(),
                    'Destination port' => $order->shipping_port,
                    'Payment terms' => $order->payment_terms,
                    'Lead time' => $order->lead_time_days ? $order->lead_time_days.' days after confirmation' : null,
                ]);
            @endphp
            @if ($terms !== [])
                <section class="border-t border-sand-200 py-6 text-[1.0625rem] dark:border-[#2c2a24]">
                    <dl class="grid gap-3 sm:grid-cols-2">
                        @foreach ($terms as $label => $value)
                            <div>
                                <dt class="text-[0.875rem] font-bold uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ $label }}</dt>
                                <dd class="mt-0.5 font-medium">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif

            {{-- The disclaimer is load-bearing; it is what keeps the sheet true. --}}
            <footer class="border-t border-sand-200 pt-6 text-[0.9375rem] leading-relaxed text-ink-soft dark:border-[#2c2a24] dark:text-[#8f887b]">
                <p>
                    This proforma invoice restates the order exactly as it was recorded when the buyer
                    accepted the supplier's quotation. It is <strong>not a tax invoice</strong>, carries no tax
                    identification number, and is not a receipt or evidence that any payment has been made.
                </p>
                <p class="mt-2">
                    Cameroon Timber Hub does not process payments and holds no funds. Settle directly with the
                    supplier on the terms shown above.
                </p>
            </footer>
        </article>
    </div>
</x-layouts.app>
