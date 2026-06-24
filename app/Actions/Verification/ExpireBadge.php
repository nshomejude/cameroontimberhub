<?php

namespace App\Actions\Verification;

use App\Enums\BadgeStatus;
use App\Models\VerificationBadge;

class ExpireBadge
{
    public function execute(VerificationBadge $badge): void
    {
        if ($badge->status !== BadgeStatus::Active) {
            return;
        }

        $badge->update(['status' => BadgeStatus::Expired]);

        activity('compliance')
            ->performedOn($badge)
            ->event('badge_expired')
            ->log('Badge expired');
    }
}
