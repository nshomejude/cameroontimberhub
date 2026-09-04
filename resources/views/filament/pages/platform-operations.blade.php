<x-filament-panels::page>
    @php
        $kpis = $this->getKpis();
        $recentOrders = $this->getRecentOrders();
        $pendingCompanies = $this->getPendingCompanies();
    @endphp

    <div class="space-y-6">
        {{-- North-Star KPIs --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">Total companies</p>
                <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($kpis['total_companies']) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">Verified companies</p>
                <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($kpis['verified_companies_count']) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">Active listings</p>
                <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($kpis['active_listings_count']) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">Monthly active companies</p>
                <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($kpis['monthly_active_companies']) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">Gross merchandise value</p>
                <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($kpis['gross_merchandise_value'], 2) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">Average order value</p>
                <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($kpis['average_order_value'], 2) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">Order fulfillment rate</p>
                <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($kpis['order_fulfillment_rate'] * 100, 1) }}%</p>
            </div>
        </div>

        {{-- Recent activity --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-1">Recent orders</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Latest 10 orders placed.</p>

                @if($recentOrders->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">No orders yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                    <th class="py-2 pr-4">#</th>
                                    <th class="py-2 pr-4">Company</th>
                                    <th class="py-2 pr-4">Status</th>
                                    <th class="py-2 pr-4">Total</th>
                                    <th class="py-2 pr-4">Placed</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($recentOrders as $order)
                                    <tr class="border-b border-gray-100 dark:border-gray-800">
                                        <td class="py-2 pr-4 text-gray-900 dark:text-white">{{ $order->id }}</td>
                                        <td class="py-2 pr-4 text-gray-900 dark:text-white">{{ $order->company?->name ?? '—' }}</td>
                                        <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $order->status->label() }}</td>
                                        <td class="py-2 pr-4 text-gray-900 dark:text-white">{{ number_format((float) $order->total_amount, 2) }}</td>
                                        <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $order->created_at?->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white mb-1">Pending verification</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Latest 10 company registrations awaiting verification.</p>

                @if($pendingCompanies->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">No companies pending verification.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                    <th class="py-2 pr-4">Company</th>
                                    <th class="py-2 pr-4">Registered</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($pendingCompanies as $company)
                                    <tr class="border-b border-gray-100 dark:border-gray-800">
                                        <td class="py-2 pr-4 text-gray-900 dark:text-white">{{ $company->name }}</td>
                                        <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $company->created_at?->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
