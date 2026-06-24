<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyStatusService;
use App\Services\VerificationService;
use App\Models\VerificationRequest;

class SubmitCompanyForReview
{
    public function __construct(
        private readonly CompanyStatusService $status,
        private readonly VerificationService $verification,
    ) {}

    public function execute(Company $company, ?User $actor = null, array $requestedBadges = []): VerificationRequest
    {
        return $this->verification->submit($company, $requestedBadges, $actor);
    }
}
