<?php

namespace App\Events;

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RfqApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Rfq $rfq,
        public readonly User $approvedBy,
    ) {}
}
