<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\Referrals\ReferralService;
use Illuminate\Console\Command;

/**
 * Give every existing user and company a referral code. Codes are also
 * generated lazily on first read (GET /api/v1/referrals/me), so this is
 * optional — run once after deploy to have codes visible in /admin.
 */
class BackfillReferralCodes extends Command
{
    protected $signature = 'referrals:backfill-codes';

    protected $description = 'Generate a unique referral code for every user and company missing one';

    public function handle(ReferralService $referrals): int
    {
        $count = 0;

        User::whereNull('referral_code')->chunkById(500, function ($users) use ($referrals, &$count): void {
            foreach ($users as $user) {
                $referrals->codeFor($user);
                $count++;
            }
        });

        Company::whereNull('referral_code')->chunkById(500, function ($companies) use ($referrals, &$count): void {
            foreach ($companies as $company) {
                $referrals->codeFor($company);
                $count++;
            }
        });

        $this->info("Generated {$count} referral codes.");

        return self::SUCCESS;
    }
}
