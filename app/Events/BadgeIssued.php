<?php

namespace App\Events;

use App\Models\User;
use App\Models\VerificationBadge;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BadgeIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly VerificationBadge $badge,
        public readonly ?User $issuedBy,
    ) {}
}
