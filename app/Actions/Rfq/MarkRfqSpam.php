<?php

namespace App\Actions\Rfq;

use App\Models\Rfq;
use App\Models\User;
use App\Services\RfqTriageService;

class MarkRfqSpam
{
    public function __construct(private readonly RfqTriageService $triage) {}

    public function execute(Rfq $rfq, User $actor): void
    {
        $this->triage->markSpam($rfq, $actor);
    }
}
