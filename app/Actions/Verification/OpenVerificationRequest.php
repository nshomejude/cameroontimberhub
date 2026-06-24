<?php

namespace App\Actions\Verification;

use App\Models\Company;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\VerificationService;

class OpenVerificationRequest
{
    public function __construct(private readonly VerificationService $verification) {}

    /**
     * @param  string[]  $requestedBadges  badge type values to request (defaults to verified_company)
     */
    public function execute(Company $company, array $requestedBadges = [], ?User $actor = null): VerificationRequest
    {
        return $this->verification->submit($company, $requestedBadges, $actor);
    }
}
