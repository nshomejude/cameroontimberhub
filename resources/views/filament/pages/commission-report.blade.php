<x-filament-panels::page>
    @php
        $rows = $this->getRows();
        $totals = $this->getTotals();
    @endphp

    <div class="fi-section rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 mb-6">
            <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                <div class="text-xs uppercase tracking-wide text-gray-500">Charged orders</div>
                <div class="text-2xl font-bold">{{ $totals['orders'] }}</div>
            </div>
            {{-- One tile per currency: money is never summed across currencies. --}}
            @foreach ($totals['by_currency'] as $currencyTotal)
                <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                    <div class="text-xs uppercase tracking-wide text-gray-500">{{ $currencyTotal['currency'] }} — GMV / net commission</div>
                    <div class="text-lg font-bold tabular-nums">{{ $currencyTotal['gmv'] }}</div>
                    <div class="text-2xl font-bold tabular-nums">{{ $currencyTotal['net_commission'] }}</div>
                </div>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10">
                        <th class="py-2 pr-4">Currency</th>
                        <th class="py-2 pr-4">Segment</th>
                        <th class="py-2 pr-4 text-right">Orders</th>
                        <th class="py-2 pr-4 text-right">GMV</th>
                        <th class="py-2 pr-4 text-right">Commission charged</th>
                        <th class="py-2 pr-4 text-right">Credited</th>
                        <th class="py-2 pr-4 text-right">Net commission</th>
                        <th class="py-2 pr-4 text-right">Take rate</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-4 font-medium">{{ $row['currency'] }}</td>
                            <td class="py-2 pr-4">{{ $row['segment'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['orders'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['gmv'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['commission'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['credited'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['net_commission'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['take_rate'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-6 text-center text-gray-500">No commission has been charged yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @php($collectionRows = $this->getCollectionRows())
    <div class="fi-section mt-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
        <h2 class="mb-1 text-base font-semibold">Commission collection</h2>
        <p class="mb-4 text-sm text-gray-500">Monthly statements paid by Mobile Money / bank deposit. Billed = non-void statements; Collected = confirmed deposits; Overdue = outstanding past the due date; Pending = reported deposits awaiting verification.</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10">
                        <th class="py-2 pr-4">Currency</th>
                        <th class="py-2 pr-4 text-right">Statements</th>
                        <th class="py-2 pr-4 text-right">Billed</th>
                        <th class="py-2 pr-4 text-right">Collected</th>
                        <th class="py-2 pr-4 text-right">Outstanding</th>
                        <th class="py-2 pr-4 text-right">Overdue</th>
                        <th class="py-2 pr-4 text-right">Pending verification</th>
                        <th class="py-2 pr-4 text-right">Collected %</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($collectionRows as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-4 font-medium">{{ $row['currency'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['statements'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['billed'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['collected'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['outstanding'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['overdue'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['pending_deposits'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['collection_rate'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-6 text-center text-gray-500">No commission statements have been issued yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @php($feeRows = $this->getProviderFeeRows())
    <div class="fi-section mt-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
        <h2 class="mb-1 text-base font-semibold">Payment provider fees</h2>
        <p class="mb-4 text-sm text-gray-500">Completed payments only. "Collected" = fee passed through to the buyer (disclosed at checkout); "Absorbed" = fee the platform paid. Net = charged − all provider fees.</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10">
                        <th class="py-2 pr-4">Provider</th>
                        <th class="py-2 pr-4">Currency</th>
                        <th class="py-2 pr-4 text-right">Payments</th>
                        <th class="py-2 pr-4 text-right">Charged</th>
                        <th class="py-2 pr-4 text-right">Fees collected</th>
                        <th class="py-2 pr-4 text-right">Fees absorbed</th>
                        <th class="py-2 pr-4 text-right">Net to platform</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($feeRows as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-4 font-medium">{{ $row['provider'] }}</td>
                            <td class="py-2 pr-4">{{ $row['currency'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['payments'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['charged'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['fees_collected'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['fees_absorbed'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['net'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-6 text-center text-gray-500">No completed payments with provider-fee data yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
