<?php

namespace App\Actions\Company;

use App\Events\CompanyVerified;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyStatusService;

class VerifyCompany
{
    public function __construct(private readonly CompanyStatusService $status) {}

    public function execute(Company $company, User $actor): Company
    {
        $company = $this->status->approve($company, $actor);

        CompanyVerified::dispatch($company, $actor);

        return $company;
    }
}
