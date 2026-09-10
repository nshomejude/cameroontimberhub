@php
    // Screen 2 — Order confirmation / order detail.
    //
    // The banner reports the order's real status. It never claims a payment was
    // taken or goods dispatched: this platform settles no money, and every
    // milestone date below is a real timestamp written by a real transition —
    // unreached milestones show no date at all.
    $company = $order->company;
    $milestones = $order->milestones();
    $isAwarded = $order->status === \App\Enums\OrderStatus::Awarded;
@endphp

<x-layouts.app
    :title="__('messages.order.title', ['ref' => $order->reference_code])"
    :description="__('messages.order.meta')"
    noindex
    :breadcrumbs="[
        ['label' => __('messages.common.home'), 'url' => route('home')],
        ['label' => $rfq->reference_code, 'url' => $access->link(request(), 'responses', $rfq)],
        ['label' => __('messages.order.crumb_order'), 'url' => url()->current()],
    ]">

    <div class="mx-auto max-w-6xl px-4 py-8 sm:py-12">

        @if (session('quote_notice'))
            <div role="status" class="mb-4 rounded-2xl border border-forest-200 bg-forest-50 px-4 py-3 text-[1.0625rem] text-forest-900 dark:border-forest-900 dark:bg-[#1b2c22] dark:text-forest-200">
                {{ session('quote_notice') }}
            </div>
        @endif

        {{-- ---------------- Status banner ---------------- --}}
        <section class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18] sm:p-7">
            <div class="flex flex-wrap items-start gap-4">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-forest-50 text-forest-700 dark:bg-[#1b2c22] dark:text-forest-300">
                    <x-heroicon-o-check-circle class="h-7 w-7" />
                </span>
                <div class="min-w-0 flex-1">
                    <h1 class="font-display text-2xl font-semibold text-forest-950 dark:text-sand-100">
                        {{ $isAwarded ? __('messages.order.awarded') : __('messages.order.status_generic', ['status' => mb_strtolower($order->status->label())]) }}
                    </h1>
                    <p class="mt-1 max-w-2xl text-[1.125rem] text-ink-soft dark:text-[#8f887b]">
                        {{ $order->status->description() }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-2 print:hidden">
                    @if ($receipt)
                        <a href="{{ $access->link(request(), 'receipt', $rfq) }}"
                           class="inline-flex items-center gap-2 rounded-full bg-forest-700 px-5 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800">
                            <x-heroicon-m-document-text class="h-4 w-4" /> {{ __('messages.order.view_print_receipt') }}
                        </a>
                    @endif
                    <a href="{{ $access->link(request(), 'responses', $rfq) }}"
                       class="inline-flex items-center gap-2 rounded-full border border-sand-300 px-5 py-2.5 text-[1.0625rem] font-semibold text-ink transition hover:border-forest-400 dark:border-[#3a372f] dark:text-[#e4ddcf]">
                        {{ __('messages.order.all_responses') }}
                    </a>
                </div>
            </div>

            {{-- Header facts — every one of these is a real column. --}}
            <dl class="mt-6 grid gap-5 border-t border-sand-200 pt-5 dark:border-[#2c2a24] sm:grid-cols-2 lg:grid-cols-5">
                @foreach ([
                    ['label' => __('messages.order.order_number'), 'value' => $order->reference_code, 'big' => false],
                    ['label' => __('messages.order.order_date'), 'value' => $order->awarded_at?->isoFormat('D MMM YYYY') ?? '—', 'big' => false],
                    ['label' => __('messages.order.status'), 'value' => $order->status->label(), 'big' => false],
                    ['label' => __('messages.order.supplier'), 'value' => $order->supplier_name, 'big' => false],
                    ['label' => __('messages.order.total_order_value'), 'value' => $order->money($order->total_amount), 'big' => true],
                ] as $fact)
                    <div>
                        <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ $fact['label'] }}</dt>
                        <dd @class([
                            'mt-1 font-semibold text-ink dark:text-[#e4ddcf]',
                            'font-display text-lg text-forest-800 dark:text-forest-300' => $fact['big'],
                        ])>{{ $fact['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="space-y-6">

                {{-- ---------------- Supplier ---------------- --}}
                <section aria-labelledby="order-supplier"
                         class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18] sm:p-7">
                    <h2 id="order-supplier" class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.order.supplier_information') }}</h2>
                    <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-lg font-semibold text-ink dark:text-[#e4ddcf]">{{ $order->supplier_name }}</p>
                            <p class="mt-1 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                                {{ collect([$company?->city, $company?->region, $company?->country_code])->filter()->implode(', ') ?: '—' }}
                            </p>
                            <div class="mt-2 space-y-1 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                                @if ($company?->email)<p>{{ $company->email }}</p>@endif
                                @if ($company?->phone)<p>{{ $company->phone }}</p>@endif
                            </div>
                        </div>
                        @if ($company?->slug)
                            <a href="{{ route('companies.show', $company->slug) }}"
                               class="inline-flex items-center gap-2 rounded-full border border-sand-300 px-4 py-2 text-[1.0625rem] font-semibold text-ink transition hover:border-forest-400 dark:border-[#3a372f] dark:text-[#e4ddcf]">
                                {{ __('messages.order.view_supplier_profile') }}
                            </a>
                        @endif
                    </div>
                </section>

                {{-- ---------------- Order items ---------------- --}}
                <section aria-labelledby="order-items"
                         class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18] sm:p-7">
                    <h2 id="order-items" class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.order.order_items') }}</h2>
                    <p class="mt-1 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                        {{ __('messages.order.items_copied_note') }}
                    </p>

                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full min-w-[42rem] text-left text-[1.0625rem]">
                            <thead>
                                <tr class="border-b border-sand-200 text-[0.875rem] uppercase tracking-wide text-ink-soft dark:border-[#2c2a24] dark:text-[#8f887b]">
                                    <th scope="col" class="py-2 pr-3 font-semibold">{{ __('messages.order.col_hash') }}</th>
                                    <th scope="col" class="py-2 pr-3 font-semibold">{{ __('messages.order.col_product') }}</th>
                                    <th scope="col" class="py-2 pr-3 font-semibold">{{ __('messages.order.col_specification') }}</th>
                                    <th scope="col" class="py-2 pr-3 text-right font-semibold">{{ __('messages.order.col_quantity') }}</th>
                                    <th scope="col" class="py-2 pr-3 text-right font-semibold">{{ __('messages.order.col_unit_price') }}</th>
                                    <th scope="col" class="py-2 text-right font-semibold">{{ __('messages.order.col_total_price') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-sand-200 dark:divide-[#2c2a24]">
                                @foreach ($order->items as $i => $item)
                                    <tr class="align-top text-ink dark:text-[#e4ddcf]">
                                        <td class="py-3 pr-3 text-ink-soft dark:text-[#8f887b]">{{ $i + 1 }}</td>
                                        <td class="py-3 pr-3 font-medium">
                                            {{ $item->description }}
                                            @if ($item->species_name)<span class="block text-[1.0625rem] font-normal text-ink-soft dark:text-[#8f887b]">{{ $item->species_name }}</span>@endif
                                        </td>
                                        <td class="py-3 pr-3 text-ink-soft dark:text-[#8f887b]">{{ collect([$item->dimensions, $item->grade])->filter()->implode(', ') ?: '—' }}</td>
                                        <td class="py-3 pr-3 text-right whitespace-nowrap">
                                            {{ rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ','), '0'), '.') }} {{ $item->unitLabel() }}
                                        </td>
                                        <td class="py-3 pr-3 text-right whitespace-nowrap">{{ $order->money($item->unit_price) }}</td>
                                        <td class="py-3 text-right font-semibold whitespace-nowrap">{{ $order->money($item->line_total) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="text-[1.0625rem]">
                                <tr>
                                    <td colspan="5" class="py-2 pr-3 text-right text-ink-soft dark:text-[#8f887b]">{{ __('messages.order.subtotal') }}</td>
                                    <td class="py-2 text-right font-semibold text-ink dark:text-[#e4ddcf]">{{ $order->money($order->subtotal_amount) }}</td>
                                </tr>
                                @if ($order->shipping_amount !== null)
                                    <tr>
                                        <td colspan="5" class="py-2 pr-3 text-right text-ink-soft dark:text-[#8f887b]">{{ __('messages.order.shipping') }}</td>
                                        <td class="py-2 text-right font-semibold text-ink dark:text-[#e4ddcf]">{{ $order->money($order->shipping_amount) }}</td>
                                    </tr>
                                @endif
                                @if ($order->tax_amount !== null)
                                    <tr>
                                        <td colspan="5" class="py-2 pr-3 text-right text-ink-soft dark:text-[#8f887b]">{{ __('messages.order.tax') }}</td>
                                        <td class="py-2 text-right font-semibold text-ink dark:text-[#e4ddcf]">{{ $order->money($order->tax_amount) }}</td>
                                    </tr>
                                @endif
                                <tr class="border-t border-sand-300 dark:border-[#3a372f]">
                                    <td colspan="5" class="py-3 pr-3 text-right font-semibold text-forest-950 dark:text-sand-100">{{ __('messages.order.total_order_value') }}</td>
                                    <td class="py-3 text-right font-display text-lg font-bold text-forest-800 dark:text-forest-300">{{ $order->money($order->total_amount) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                {{-- ---------------- Terms + progress ---------------- --}}
                <div class="grid gap-6 md:grid-cols-2">
                    <section aria-labelledby="order-terms"
                             class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18] sm:p-6">
                        <h2 id="order-terms" class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.order.order_terms') }}</h2>
                        <dl class="mt-4 space-y-3 text-[1.0625rem]">
                            @foreach ([
                                __('messages.order.payment_terms') => $order->payment_terms ?: __('messages.order.not_stated'),
                                __('messages.order.incoterms') => $order->incoterm?->value ?: __('messages.order.not_stated'),
                                __('messages.order.lead_time') => $order->lead_time_days ? __('messages.order.lead_time_days', ['count' => $order->lead_time_days]) : __('messages.order.not_stated'),
                                __('messages.order.expected_delivery') => $order->expected_delivery_at?->isoFormat('D MMM YYYY') ?? __('messages.order.not_stated'),
                                __('messages.order.port_destination') => $order->shipping_port ?: ($order->destination_country_code ?: __('messages.order.not_stated')),
                            ] as $label => $value)
                                <div class="flex justify-between gap-4">
                                    <dt class="text-ink-soft dark:text-[#8f887b]">{{ $label }}</dt>
                                    <dd class="text-right font-medium text-ink dark:text-[#e4ddcf]">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>

                    <section aria-labelledby="order-progress"
                             class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18] sm:p-6">
                        <h2 id="order-progress" class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.order.progress') }}</h2>
                        <p class="mt-1 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                            {{ __('messages.order.progress_note') }}
                        </p>
                        <ol class="mt-4 space-y-3">
                            @foreach ($milestones as $milestone)
                                <li class="flex items-start gap-3 text-[1.0625rem]">
                                    <span @class([
                                        'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border',
                                        'border-forest-600 bg-forest-600 text-white' => $milestone['reached'],
                                        'border-sand-300 text-transparent dark:border-[#3a372f]' => ! $milestone['reached'],
                                    ])>
                                        <x-heroicon-m-check class="h-3.5 w-3.5" />
                                    </span>
                                    <span class="flex-1">
                                        <span @class([
                                            'font-medium',
                                            'text-ink dark:text-[#e4ddcf]' => $milestone['reached'],
                                            'text-ink-soft dark:text-[#8f887b]' => ! $milestone['reached'],
                                        ])>{{ $milestone['status']->label() }}</span>
                                        <span class="block text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                                            {{ $milestone['at']?->isoFormat('D MMM YYYY, HH:mm') ?? __('messages.order.not_yet') }}
                                        </span>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                        @if ($order->status === \App\Enums\OrderStatus::Cancelled && $order->cancellation_reason)
                            <p class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-[1.0625rem] text-red-800 dark:bg-red-950 dark:text-red-200">
                                {{ __('messages.order.cancelled_reason', ['reason' => $order->cancellation_reason]) }}
                            </p>
                        @endif
                    </section>
                </div>
            </div>

            {{-- ---------------- Sidebar ---------------- --}}
            <aside class="space-y-4">
                <section class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                    <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.order.request') }}</h2>
                    <dl class="mt-4 space-y-3 text-[1.0625rem]">
                        @foreach (array_filter([
                            __('messages.order.rfq_reference') => $rfq->reference_code,
                            __('messages.order.rfq_title') => $rfq->title,
                            __('messages.order.quote_awarded') => $order->quote?->reference_code,
                            __('messages.order.total_quantity') => $order->totalQuantity(),
                        ]) as $label => $value)
                            <div>
                                <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">{{ $label }}</dt>
                                <dd class="mt-0.5 font-medium text-ink dark:text-[#e4ddcf]">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                {{-- Settlement. Truthful about the fact that we settle nothing. --}}
                <section class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                    <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.order.settlement') }}</h2>
                    <dl class="mt-4 space-y-3 text-[1.0625rem]">
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-soft dark:text-[#8f887b]">{{ __('messages.order.status') }}</dt>
                            <dd class="text-right font-medium text-ink dark:text-[#e4ddcf]">{{ $order->payment_status->label() }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-soft dark:text-[#8f887b]">{{ __('messages.order.recorded_as_paid') }}</dt>
                            <dd class="text-right font-medium text-ink dark:text-[#e4ddcf]">{{ $order->money($order->amount_paid) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-soft dark:text-[#8f887b]">{{ __('messages.order.balance') }}</dt>
                            <dd class="text-right font-medium text-ink dark:text-[#e4ddcf]">{{ $order->money($order->balanceDue()) }}</dd>
                        </div>
                    </dl>
                    <p class="mt-4 text-[0.9375rem] leading-relaxed text-ink-soft dark:text-[#b3ab9b]">
                        {{ __('messages.order.settlement_note') }}
                    </p>
                </section>

                @if ($receipt)
                    <section class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                        <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.order.receipt') }}</h2>
                        <p class="mt-3 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">{{ __('messages.order.receipt_number') }}</p>
                        <p class="font-mono text-[1.125rem] font-semibold text-forest-800 dark:text-forest-300">{{ $receipt->receipt_number }}</p>
                        <p class="mt-2 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                            {{ __('messages.order.issued_at', ['date' => $receipt->issued_at->isoFormat('D MMM YYYY, HH:mm')]) }}
                        </p>
                        <a href="{{ $access->link(request(), 'receipt', $rfq) }}"
                           class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-full border border-sand-300 px-4 py-2.5 text-[1.0625rem] font-semibold text-ink transition hover:border-forest-400 dark:border-[#3a372f] dark:text-[#e4ddcf]">
                            {{ __('messages.order.open_receipt') }}
                        </a>
                    </section>
                @endif

                <section class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                    <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.order.protection_resolution') }}</h2>
                    <p class="mt-3 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                        {{ __('messages.order.protection_body') }}
                    </p>
                    <a href="{{ route('account.orders.trade-assurance', ['order' => $order->id]) }}"
                       class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-full border border-sand-300 px-4 py-2.5 text-[1.0625rem] font-semibold text-ink transition hover:border-forest-400 dark:border-[#3a372f] dark:text-[#e4ddcf]">
                        {{ __('messages.order.trade_assurance') }}
                    </a>
                    <a href="{{ route('disputes.index', ['order' => $order->id]) }}"
                       class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-full border border-sand-300 px-4 py-2.5 text-[1.0625rem] font-semibold text-ink transition hover:border-forest-400 dark:border-[#3a372f] dark:text-[#e4ddcf]">
                        {{ __('messages.order.disputes') }}
                    </a>
                </section>

                <section class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                    <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">{{ __('messages.order.next_steps') }}</h2>
                    <ol class="mt-3 list-decimal space-y-2 pl-4 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                        <li>{{ __('messages.order.next_1') }}</li>
                        <li>{{ __('messages.order.next_2') }}</li>
                        <li>{{ __('messages.order.next_3') }}</li>
                    </ol>
                    <a href="{{ route('contact') }}"
                       class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-full border border-sand-300 px-4 py-2.5 text-[1.0625rem] font-semibold text-ink transition hover:border-forest-400 dark:border-[#3a372f] dark:text-[#e4ddcf]">
                        {{ __('messages.order.contact_support') }}
                    </a>
                </section>
            </aside>
        </div>
    </div>
</x-layouts.app>
