<?php

namespace App\Events;

use App\Models\Company;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RfqRoutedToCompany
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Rfq $rfq,
        public readonly Company $company,
        public readonly User $routedBy,
    ) {}
}
