<?php

namespace App\Actions\Verification;

use App\Events\BadgeRevoked;
use App\Models\User;
use App\Models\VerificationBadge;
use App\Services\BadgeService;

class RevokeBadge
{
    public function __construct(private readonly BadgeService $badges) {}

    public function execute(VerificationBadge $badge, string $reason, ?User $actor): void
    {
        $this->badges->revoke($badge, $reason, $actor);

        BadgeRevoked::dispatch($badge->fresh(), $actor, $reason);
    }
}
