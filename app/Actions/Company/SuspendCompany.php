<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyStatusService;

class SuspendCompany
{
    public function __construct(private readonly CompanyStatusService $status) {}

    public function execute(Company $company, string $reason, User $actor): Company
    {
        return $this->status->suspend($company, $reason, $actor);
    }
}
