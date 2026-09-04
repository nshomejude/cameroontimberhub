<?php

namespace App\Console\Commands;

use App\Models\MarketIntelligenceSnapshot;
use App\Services\MarketIntelligenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Blueprint §33-34 Market Intelligence: computes today's Price / Demand /
 * Supplier Performance indices and stores one snapshot row per dimension, so
 * later trend charts can read history without recomputing it from raw
 * orders/RFQs every time.
 *
 * Safe on an empty database: each index method already returns an empty
 * Collection rather than throwing, so this command simply writes no rows for
 * an index with nothing behind it and exits successfully.
 */
class MarketIntelligenceSnapshotCommand extends Command
{
    protected $signature = 'market-intel:snapshot';

    protected $description = 'Compute and store a daily Market Intelligence snapshot (price, demand, supplier performance)';

    public function handle(MarketIntelligenceService $service): int
    {
        $today = Carbon::today();
        $written = 0;

        // Price index: one snapshot per species that had real sales this period.
        foreach ($service->priceIndexBySpecies() as $row) {
            MarketIntelligenceSnapshot::query()->updateOrCreate(
                [
                    'snapshot_date' => $today,
                    'index_type' => 'price_index',
                    'dimension' => (string) $row['species_id'],
                ],
                [
                    'value' => $row['avg_unit_price'],
                    'metadata' => [
                        'species_name' => $row['species_name'],
                        'month' => $row['month'],
                        'sample_size' => $row['sample_size'],
                    ],
                ],
            );
            $written++;
        }

        // Demand index: overall RFQ volume for the current month, if any.
        $demand = $service->demandIndex(1)->last();

        if ($demand !== null) {
            MarketIntelligenceSnapshot::query()->updateOrCreate(
                [
                    'snapshot_date' => $today,
                    'index_type' => 'demand_index',
                    'dimension' => 'overall',
                ],
                [
                    'value' => $demand['rfq_count'],
                    'metadata' => [
                        'month' => $demand['month'],
                        'total_quantity' => $demand['total_quantity'],
                    ],
                ],
            );
            $written++;
        }

        // Supplier performance: one snapshot per qualifying company.
        foreach ($service->supplierPerformanceIndex() as $row) {
            MarketIntelligenceSnapshot::query()->updateOrCreate(
                [
                    'snapshot_date' => $today,
                    'index_type' => 'supplier_performance_index',
                    'dimension' => (string) $row['company_id'],
                ],
                [
                    'value' => $row['average_order_value'],
                    'metadata' => [
                        'company_name' => $row['company_name'],
                        'on_time_delivery_percent' => $row['on_time_delivery_percent'],
                        'completed_order_count' => $row['completed_order_count'],
                    ],
                ],
            );
            $written++;
        }

        $this->info("Market intelligence snapshot: wrote {$written} row(s) for {$today->toDateString()}.");

        return self::SUCCESS;
    }
}
