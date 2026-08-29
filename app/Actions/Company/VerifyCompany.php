<?php

namespace App\Actions\Company;

use App\Events\CompanyVerified;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyStatusService;
use App\Services\CompanyVerificationMirror;

class VerifyCompany
{
    public function __construct(
        private readonly CompanyStatusService $status,
        private readonly CompanyVerificationMirror $mirror,
    ) {}

    public function execute(Company $company, User $actor): Company
    {
        $company = $this->status->approve($company, $actor);

        CompanyVerified::dispatch($company, $actor);

        $this->mirror->approved($company, $actor);

        return $company;
    }
}
