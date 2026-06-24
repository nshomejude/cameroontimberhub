<?php

namespace App\Actions\Rfq;

use App\Enums\RfqStatus;
use App\Events\RfqRoutedToCompany;
use App\Models\Company;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\LeadFlowService;
use RuntimeException;

class RouteRfqToCompanies
{
    public function __construct(private readonly LeadFlowService $leads) {}

    /**
     * Route an approved RFQ to verified exporters (idempotent per company).
     *
     * @param  list<int>  $companyIds
     */
    public function execute(Rfq $rfq, array $companyIds, User $actor): int
    {
        if ($rfq->status !== RfqStatus::Approved) {
            throw new RuntimeException('Only approved RFQs can be routed.');
        }

        $routed = 0;

        foreach ($companyIds as $companyId) {
            $routing = RfqCompany::firstOrCreate(
                ['rfq_id' => $rfq->getKey(), 'company_id' => $companyId],
                ['status' => 'sent', 'routed_by' => $actor->getKey(), 'routed_at' => now()],
            );

            if ($routing->wasRecentlyCreated) {
                $this->leads->createFromRouting($routing);

                $company = Company::find($companyId);
                if ($company) {
                    RfqRoutedToCompany::dispatch($rfq, $company, $actor);
                }

                $routed++;
            }
        }

        return $routed;
    }
}
