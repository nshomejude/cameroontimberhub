<?php

namespace App\Actions\Rfq;

use App\Models\Rfq;
use App\Services\IntakeService;

class ConfirmRfqEmail
{
    public function __construct(private readonly IntakeService $intake) {}

    public function execute(Rfq $rfq): void
    {
        $this->intake->verifyRfq($rfq);
    }
}
