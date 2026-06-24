<?php

namespace App\Actions\Rfq;

use App\Events\RfqApproved;
use App\Models\Rfq;
use App\Models\User;
use App\Services\RfqTriageService;

class ApproveRfq
{
    public function __construct(private readonly RfqTriageService $triage) {}

    public function execute(Rfq $rfq, User $actor): void
    {
        $this->triage->approve($rfq, $actor);

        RfqApproved::dispatch($rfq->fresh(), $actor);
    }
}
