<?php

namespace App\Actions\Verification;

use App\Models\Company;
use App\Models\User;
use App\Models\VerificationRevocationRequest;

/**
 * First step of the two-person revocation control (blueprint §89): records
 * intent to revoke a company's verification without changing anything else.
 * Nothing about the company's Verification/VerificationBadge state changes
 * until a DIFFERENT staff member approves via ApproveVerificationRevocation.
 */
class RequestVerificationRevocation
{
    public function execute(Company $company, string $reason, User $requestedBy): VerificationRevocationRequest
    {
        return VerificationRevocationRequest::create([
            'company_id' => $company->getKey(),
            'requested_by' => $requestedBy->getKey(),
            'reason' => $reason,
            'status' => 'pending',
        ]);
    }
}
