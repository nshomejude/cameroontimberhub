<x-filament-panels::page>
    @php
        $priceIndex = $this->getPriceIndex();
        $demandIndex = $this->getDemandIndex();
        $supplierIndex = $this->getSupplierPerformanceIndex();
    @endphp

    <div class="space-y-6">
        {{-- Price Index --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-1">Price Index</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Average awarded-order unit price per species, by month.</p>

            @if($priceIndex->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">No priced order data yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                <th class="py-2 pr-4">Month</th>
                                <th class="py-2 pr-4">Species</th>
                                <th class="py-2 pr-4">Avg. unit price</th>
                                <th class="py-2 pr-4">Sample size</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($priceIndex as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-4 text-gray-900 dark:text-white">{{ $row['month'] }}</td>
                                    <td class="py-2 pr-4 text-gray-900 dark:text-white">{{ $row['species_name'] }}</td>
                                    <td class="py-2 pr-4 text-gray-900 dark:text-white">{{ number_format($row['avg_unit_price'], 2) }}</td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $row['sample_size'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Demand Index --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-1">Demand Index</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Verified RFQ volume and total requested quantity, by month.</p>

            @if($demandIndex->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">No RFQ data yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                <th class="py-2 pr-4">Month</th>
                                <th class="py-2 pr-4">RFQ count</th>
                                <th class="py-2 pr-4">Total requested quantity</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($demandIndex as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-4 text-gray-900 dark:text-white">{{ $row['month'] }}</td>
                                    <td class="py-2 pr-4 text-gray-900 dark:text-white">{{ $row['rfq_count'] }}</td>
                                    <td class="py-2 pr-4 text-gray-900 dark:text-white">{{ number_format($row['total_quantity'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Supplier Performance Index --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-1">Supplier Performance Index</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Companies with at least one completed order.</p>

            @if($supplierIndex->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">No completed orders yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                <th class="py-2 pr-4">Company</th>
                                <th class="py-2 pr-4">On-time delivery</th>
                                <th class="py-2 pr-4">Completed orders</th>
                                <th class="py-2 pr-4">Avg. order value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($supplierIndex as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-4 text-gray-900 dark:text-white">{{ $row['company_name'] }}</td>
                                    <td class="py-2 pr-4 text-gray-900 dark:text-white">{{ $row['on_time_delivery_percent'] !== null ? $row['on_time_delivery_percent'].'%' : '—' }}</td>
                                    <td class="py-2 pr-4 text-gray-900 dark:text-white">{{ $row['completed_order_count'] }}</td>
                                    <td class="py-2 pr-4 text-gray-900 dark:text-white">{{ number_format($row['average_order_value'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
