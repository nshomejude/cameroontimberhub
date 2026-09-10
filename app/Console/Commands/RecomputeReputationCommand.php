<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Order;
use App\Services\ReputationService;
use Illuminate\Console\Command;

/**
 * Production-readiness plan Task C1 (blueprint §1.10): nightly rebuild of every
 * company's reputation figures from real order / RFQ / dispute rows.
 *
 * Only companies with at least one order are touched — a company with no
 * commercial history keeps its null/zero figures rather than being handed a
 * fabricated number.
 */
class RecomputeReputationCommand extends Command
{
    protected $signature = 'reputation:recompute';

    protected $description = 'Rebuild every trading company\'s reputation figures from real order, RFQ and dispute rows';

    public function handle(ReputationService $service): int
    {
        $count = 0;

        Company::query()
            ->whereIn('id', Order::query()->select('company_id')->distinct())
            ->chunkById(200, function ($companies) use ($service, &$count) {
                foreach ($companies as $company) {
                    $service->recompute($company);
                    $count++;
                }
            });

        $this->info("Recomputed reputation for {$count} companies.");

        return self::SUCCESS;
    }
}
