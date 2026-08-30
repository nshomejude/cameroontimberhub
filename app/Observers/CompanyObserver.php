<?php

namespace App\Observers;

use App\Models\Company;
use App\Models\Plan;
use App\Services\SubscriptionService;

/**
 * Every company needs a plan_id for Company::hasFeature() (and the
 * leads_receive RFQ-routing gate) to mean anything — plan_id defaults to
 * NULL at the schema level and nothing else ever sets it. This assigns the
 * Free plan to a newly created company unless something else (a seeder, an
 * admin action running in the same request) has already set plan_id.
 */
class CompanyObserver
{
    public function created(Company $company): void
    {
        if ($company->plan_id !== null) {
            return;
        }

        $freePlan = Plan::where('slug', 'free')->first();

        if (! $freePlan) {
            return;
        }

        app(SubscriptionService::class)->assign($company, $freePlan);

        // assign() sets plan_id on the in-memory model via update(), so the
        // caller sees the assigned plan without a fresh fetch.
    }
}
