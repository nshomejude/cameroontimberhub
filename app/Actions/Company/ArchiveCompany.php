<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyStatusService;

class ArchiveCompany
{
    public function __construct(private readonly CompanyStatusService $status) {}

    public function execute(Company $company, ?User $actor = null): Company
    {
        return $this->status->archive($company, $actor);
    }
}
