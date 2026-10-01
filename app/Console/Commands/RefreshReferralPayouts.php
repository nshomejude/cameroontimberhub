<?php

namespace App\Console\Commands;

use App\Services\Referrals\ReferralPayoutService;
use Illuminate\Console\Command;

/**
 * Safety net behind the PayPal payout webhooks: re-checks every PayPal
 * referral payout still `processing` / `unclaimed` (GET the batch), and
 * safely re-submits any whose submission outcome is unknown (same
 * sender_batch_id — PayPal deduplicates). No-op when PayPal is not configured.
 */
class RefreshReferralPayouts extends Command
{
    protected $signature = 'referrals:refresh-payouts';

    protected $description = 'Refresh the status of in-flight PayPal referral commission payouts';

    public function handle(ReferralPayoutService $payouts): int
    {
        $count = $payouts->refreshOpen();

        $this->info("Checked {$count} referral payout(s).");

        return self::SUCCESS;
    }
}
