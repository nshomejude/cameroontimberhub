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
            <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                <div class="text-xs uppercase tracking-wide text-gray-500">GMV (subtotal)</div>
                <div class="text-2xl font-bold">{{ $totals['gmv'] }}</div>
            </div>
            <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                <div class="text-xs uppercase tracking-wide text-gray-500">Net commission</div>
                <div class="text-2xl font-bold">{{ $totals['net_commission'] }}</div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10">
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
                            <td class="py-2 pr-4 font-medium">{{ $row['segment'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['orders'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['gmv'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['commission'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['credited'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['net_commission'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['take_rate'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-6 text-center text-gray-500">No commission has been charged yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
