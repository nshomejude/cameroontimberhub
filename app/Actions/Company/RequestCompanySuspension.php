<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\CompanySuspensionRequest;
use App\Models\User;

/**
 * First step of the two-person suspension control (blueprint §88-89):
 * records intent to suspend a company without changing anything else.
 * Nothing about the company's status changes until a DIFFERENT staff
 * member approves via ApproveCompanySuspension.
 */
class RequestCompanySuspension
{
    public function execute(Company $company, string $reason, User $requestedBy): CompanySuspensionRequest
    {
        return CompanySuspensionRequest::create([
            'company_id' => $company->getKey(),
            'requested_by' => $requestedBy->getKey(),
            'reason' => $reason,
            'status' => 'pending',
        ]);
    }
}
