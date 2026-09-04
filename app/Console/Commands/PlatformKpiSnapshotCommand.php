<?php

namespace App\Console\Commands;

use App\Models\PlatformKpiSnapshot;
use App\Services\PlatformKpiService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Blueprint §66-69 Platform Operations: computes today's North-Star KPI
 * figures and stores one row for the day, so later trend charts can read
 * history without recomputing it from raw companies/orders every time.
 *
 * Idempotent: `date` is unique on platform_kpi_snapshots, so re-running this
 * command on the same day upserts the existing row rather than inserting a
 * duplicate.
 */
class PlatformKpiSnapshotCommand extends Command
{
    protected $signature = 'platform:kpi-snapshot';

    protected $description = 'Compute and store today\'s Platform Operations KPI snapshot.';

    public function handle(PlatformKpiService $kpis): int
    {
        $today = Carbon::today();

        PlatformKpiSnapshot::query()->updateOrCreate(
            ['date' => $today],
            ['metrics' => $kpis->snapshot()],
        );

        $this->info("Platform KPI snapshot written for {$today->toDateString()}.");

        return self::SUCCESS;
    }
}
