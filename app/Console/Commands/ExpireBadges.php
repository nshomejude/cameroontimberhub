<?php

namespace App\Console\Commands;

use App\Enums\BadgeStatus;
use App\Models\VerificationBadge;
use Illuminate\Console\Command;

/**
 * Daily lifecycle sweep: flips active badges past their valid_until to expired.
 * A company that loses its last active badge silently drops from public surfaces
 * (the publiclyVisible scope filters on active, unexpired badges).
 */
class ExpireBadges extends Command
{
    protected $signature = 'compliance:expire-badges';

    protected $description = 'Expire verification badges past their valid_until date';

    public function handle(): int
    {
        $expired = VerificationBadge::query()
            ->where('status', BadgeStatus::Active->value)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', today())
            ->update(['status' => BadgeStatus::Expired->value]);

        $this->info("Expired {$expired} badge(s).");

        return self::SUCCESS;
    }
}
