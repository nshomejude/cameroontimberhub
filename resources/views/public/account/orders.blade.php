<x-layouts.account
    title="Orders"
    heading="Orders"
    subheading="Orders awarded from your requests. Every figure is the snapshot taken at award time.">

    @if ($orders->total() === 0)
        <x-account.blank icon="clipboard-document-check"
            title="No orders yet"
            body="An order is created the moment you award a quote. It freezes the agreed lines, prices and terms, and carries a verifiable receipt."
            cta-label="Review your quotes" :cta-url="route('account.quotes')" />
    @else
        <p class="mb-3 text-[0.8125rem] text-ink-soft">
            {{ number_format($orders->total()) }} {{ Str::plural('order', $orders->total()) }}
        </p>

        {{-- Desktop table --}}
        <div class="hidden overflow-x-auto rounded-2xl border border-sand-200 bg-white lg:block">
            <table class="w-full min-w-[52rem] text-left text-[0.875rem]">
                <thead>
                    <tr class="border-b border-sand-200 text-[0.6875rem] uppercase tracking-wide text-ink-soft">
                        <th scope="col" class="py-3 pl-5 pr-3 font-semibold">Order</th>
                        <th scope="col" class="py-3 pr-3 font-semibold">Supplier</th>
                        <th scope="col" class="py-3 pr-3 font-semibold">Order value</th>
                        <th scope="col" class="py-3 pr-3 font-semibold">Status</th>
                        <th scope="col" class="py-3 pr-3 font-semibold">Payment</th>
                        <th scope="col" class="py-3 pr-3 font-semibold">Awarded</th>
                        <th scope="col" class="py-3 pr-5 font-semibold">Receipt</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sand-200">
                    @foreach ($orders as $order)
                        <tr class="transition hover:bg-sand-50">
                            <td class="py-3 pl-5 pr-3">
                                <a href="{{ $access->link(request(), 'order', $order->rfq) }}"
                                   class="font-semibold text-forest-700 transition hover:text-forest-900">{{ $order->reference_code }}</a>
                            </td>
                            <td class="py-3 pr-3 text-ink">{{ $order->supplier_name }}</td>
                            <td class="py-3 pr-3 font-semibold text-ink">{{ $order->money($order->total_amount) }}</td>
                            <td class="py-3 pr-3"><x-account.status-pill :label="$order->status->label()" :color="$order->status->color()" /></td>
                            <td class="py-3 pr-3"><x-account.status-pill :label="$order->payment_status->label()" :color="$order->payment_status->color()" /></td>
                            <td class="py-3 pr-3 text-ink-soft">{{ $order->awarded_at?->isoFormat('D MMM YYYY') ?? '—' }}</td>
                            <td class="py-3 pr-5">
                                @if ($order->receipt)
                                    <a href="{{ $access->link(request(), 'receipt', $order->rfq) }}"
                                       class="font-semibold text-forest-700 transition hover:text-forest-900">{{ $order->receipt->receipt_number }}</a>
                                @else
                                    <span class="text-ink-soft">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Mobile cards --}}
        <ul class="space-y-3 lg:hidden">
            @foreach ($orders as $order)
                <li class="rounded-2xl border border-sand-200 bg-white p-4">
                    <div class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <a href="{{ $access->link(request(), 'order', $order->rfq) }}"
                               class="block truncate text-[0.9375rem] font-semibold text-forest-700">{{ $order->reference_code }}</a>
                            <p class="truncate text-[0.8125rem] text-ink-soft">{{ $order->supplier_name }}</p>
                        </div>
                        <x-account.status-pill :label="$order->status->label()" :color="$order->status->color()" />
                    </div>
                    <div class="mt-3 flex items-end justify-between gap-3 border-t border-sand-200 pt-3">
                        <span class="font-display text-lg font-bold text-forest-800">{{ $order->money($order->total_amount) }}</span>
                        @if ($order->receipt)
                            <a href="{{ $access->link(request(), 'receipt', $order->rfq) }}"
                               class="text-[0.8125rem] font-semibold text-forest-700">View receipt</a>
                        @else
                            <span class="text-[0.75rem] text-ink-soft">{{ $order->payment_status->label() }}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-5"><x-account.pagination :paginator="$orders" noun="orders" /></div>
    @endif
</x-layouts.account>
