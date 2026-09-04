<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Rfq;
use App\Models\RfqItem;
use Illuminate\Support\Collection;

/**
 * Blueprint §33-34 Market Intelligence: a Price Index, Demand Index, and
 * Supplier Performance Index computed strictly from real transactional data
 * already recorded elsewhere on the platform (orders, RFQs, companies).
 *
 * Nothing here fabricates or interpolates a figure. Every method degrades to
 * an empty Collection when there is not enough real data to compute from --
 * never to a placeholder number.
 */
class MarketIntelligenceService
{
    /**
     * Average unit price per month, from awarded order line items (the real
     * money that changed hands), optionally narrowed to one species.
     *
     * Each row: month (Y-m), species_id, species_name, avg_unit_price,
     * sample_size (number of order lines behind the average).
     */
    public function priceIndexBySpecies(?int $speciesId = null, int $months = 6): Collection
    {
        $since = now()->subMonths(max(1, $months))->startOfMonth();

        $query = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotNull('order_items.species_id')
            ->where('order_items.unit_price', '>', 0)
            ->where('orders.awarded_at', '>=', $since)
            ->when($speciesId !== null, fn ($q) => $q->where('order_items.species_id', $speciesId))
            ->join('species', 'species.id', '=', 'order_items.species_id')
            ->selectRaw("to_char(orders.awarded_at, 'YYYY-MM') as month")
            ->addSelect('order_items.species_id')
            ->selectRaw('species.common_name as species_name')
            ->selectRaw('avg(order_items.unit_price) as avg_unit_price')
            ->selectRaw('count(*) as sample_size')
            ->groupBy('month', 'order_items.species_id', 'species.common_name')
            ->orderBy('month');

        return $query->get()->map(fn ($row) => [
            'month' => $row->month,
            'species_id' => (int) $row->species_id,
            'species_name' => $row->species_name,
            'avg_unit_price' => round((float) $row->avg_unit_price, 2),
            'sample_size' => (int) $row->sample_size,
        ]);
    }

    /**
     * RFQ demand volume per month: request count and total requested
     * quantity, optionally narrowed to one species. Quantity is summed only
     * where it is recorded -- a request left blank contributes 0, never a
     * guessed figure.
     *
     * Each row: month (Y-m), rfq_count, total_quantity.
     */
    public function demandIndex(int $months = 6, ?int $speciesId = null): Collection
    {
        $since = now()->subMonths(max(1, $months))->startOfMonth();

        $query = Rfq::query()
            ->verified()
            ->where('rfqs.created_at', '>=', $since)
            ->when($speciesId !== null, fn ($q) => $q->whereHas('items', fn ($i) => $i->where('species_id', $speciesId)))
            ->selectRaw("to_char(rfqs.created_at, 'YYYY-MM') as month")
            ->selectRaw('count(distinct rfqs.id) as rfq_count')
            ->groupBy('month')
            ->orderBy('month');

        $counts = $query->get()->keyBy('month');

        $quantities = RfqItem::query()
            ->join('rfqs', 'rfqs.id', '=', 'rfq_items.rfq_id')
            ->whereNotNull('rfqs.email_verified_at')
            ->where('rfqs.created_at', '>=', $since)
            ->when($speciesId !== null, fn ($q) => $q->where('rfq_items.species_id', $speciesId))
            ->selectRaw("to_char(rfqs.created_at, 'YYYY-MM') as month")
            ->selectRaw('coalesce(sum(rfq_items.quantity), 0) as total_quantity')
            ->groupBy('month')
            ->get()
            ->keyBy('month');

        return $counts->keys()
            ->merge($quantities->keys())
            ->unique()
            ->sort()
            ->values()
            ->map(fn ($month) => [
                'month' => $month,
                'rfq_count' => (int) ($counts[$month]->rfq_count ?? 0),
                'total_quantity' => round((float) ($quantities[$month]->total_quantity ?? 0), 2),
            ]);
    }

    /**
     * Per-company supplier performance, limited to companies with at least
     * one completed order (there is no other real fulfilment signal to
     * measure against). `on_time_delivery_percent` is read verbatim off the
     * company record rather than recomputed here -- it is maintained
     * elsewhere as the platform's single source for that figure.
     *
     * Each row: company_id, company_name, on_time_delivery_percent,
     * completed_order_count, average_order_value.
     */
    public function supplierPerformanceIndex(): Collection
    {
        $rows = Order::query()
            ->where('status', OrderStatus::Completed->value)
            ->selectRaw('company_id')
            ->selectRaw('count(*) as completed_order_count')
            ->selectRaw('avg(total_amount) as average_order_value')
            ->groupBy('company_id')
            ->havingRaw('count(*) >= 1')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $companies = Company::query()
            ->whereIn('id', $rows->pluck('company_id'))
            ->get(['id', 'legal_name', 'trade_name', 'on_time_delivery_percent'])
            ->keyBy('id');

        return $rows->map(function ($row) use ($companies) {
            $company = $companies->get($row->company_id);

            return [
                'company_id' => (int) $row->company_id,
                'company_name' => $company?->name,
                'on_time_delivery_percent' => $company?->on_time_delivery_percent,
                'completed_order_count' => (int) $row->completed_order_count,
                'average_order_value' => round((float) $row->average_order_value, 2),
            ];
        })->filter(fn ($row) => $row['company_name'] !== null)->values();
    }
}
