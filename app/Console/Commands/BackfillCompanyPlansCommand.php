<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Plan;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Backfills plan_id on every existing company that has none — every company
 * created before CompanyObserver::created() existed, plus any row that
 * somehow slipped through since. Idempotent and safe to re-run: it only ever
 * touches rows where `plan_id IS NULL`, so a company that already carries a
 * plan (assigned by hand, by this command's previous run, or by the
 * CompanyObserver on creation) is left untouched.
 */
class BackfillCompanyPlansCommand extends Command
{
    protected $signature = 'companies:backfill-plans';

    protected $description = 'Assign the Free plan to every company with no plan_id.';

    public function handle(SubscriptionService $subscriptions): int
    {
        $freePlan = Plan::where('slug', 'free')->first();

        if (! $freePlan) {
            $this->error('No plan with slug=free exists. Nothing to backfill.');

            return self::FAILURE;
        }

        $total = 0;

        Company::query()
            ->whereNull('plan_id')
            ->each(function (Company $company) use ($subscriptions, $freePlan, &$total): void {
                $subscriptions->assign($company, $freePlan);
                $total++;
            });

        $this->info("Backfilled {$total} compan".($total === 1 ? 'y' : 'ies')." to the Free plan.");

        return self::SUCCESS;
    }
}
