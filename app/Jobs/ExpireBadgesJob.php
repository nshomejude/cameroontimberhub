<?php

namespace App\Jobs;

use App\Enums\BadgeStatus;
use App\Models\VerificationBadge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireBadgesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function handle(): void
    {
        VerificationBadge::query()
            ->where('status', BadgeStatus::Active->value)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', today())
            ->update(['status' => BadgeStatus::Expired->value]);
    }
}
