<?php

namespace App\Events;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CompanyVerified
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Company $company,
        public readonly ?User $verifiedBy,
    ) {}
}
