<?php

namespace App\Events;

use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlanAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Company $company,
        public readonly Plan $plan,
        public readonly ?User $assignedBy,
    ) {}
}
