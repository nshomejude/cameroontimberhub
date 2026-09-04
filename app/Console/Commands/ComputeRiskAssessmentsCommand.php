<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\RiskAssessment;
use Illuminate\Console\Command;

/**
 * Blueprint §7 Supplier Risk Engine: computes and APPENDS a fresh
 * RiskAssessment row for every company. Deliberately appends rather than
 * upserting — a company builds a history of assessments over time so a
 * trend can later be read off it. Scheduling this command is out of scope
 * here; it is invoked manually or wired up separately.
 */
class ComputeRiskAssessmentsCommand extends Command
{
    protected $signature = 'risk:compute-all';

    protected $description = 'Compute and store a fresh risk assessment for every company';

    public function handle(): int
    {
        $count = 0;

        Company::query()->chunkById(100, function ($companies) use (&$count) {
            foreach ($companies as $company) {
                RiskAssessment::computeFor($company)->save();
                $count++;
            }
        });

        $this->info("Computed risk assessments for {$count} companies.");

        return self::SUCCESS;
    }
}
