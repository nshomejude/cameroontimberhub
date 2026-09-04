<?php

namespace App\Observers;

use App\Models\Company;
use App\Models\Plan;
use App\Services\FraudDetectionService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        if ($company->plan_id === null) {
            $freePlan = Plan::where('slug', 'free')->first();

            if ($freePlan) {
                app(SubscriptionService::class)->assign($company, $freePlan);

                // assign() sets plan_id on the in-memory model via update(), so
                // the caller sees the assigned plan without a fresh fetch.
            }
        }

        $this->detectDuplicateCompany($company);
    }

    /**
     * Blueprint §25 duplicate-entity detection — detection/alerting only,
     * wrapped separately from the plan assignment above so a bug here can
     * never prevent a company (or its plan assignment) from being created.
     */
    private function detectDuplicateCompany(Company $company): void
    {
        try {
            app(FraudDetectionService::class)->detectDuplicateCompanies($company);
        } catch (Throwable $e) {
            Log::error('CompanyObserver failed to run duplicate-company detection', [
                'company_id' => $company->id ?? null,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
