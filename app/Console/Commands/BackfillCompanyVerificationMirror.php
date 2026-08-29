<?php

namespace App\Console\Commands;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyVerificationMirror;
use Illuminate\Console\Command;

/**
 * One-time backfill of the read-only Company -> Verification mirror (item
 * 0.2b v2). Only covers pending/verified/rejected/suspended companies —
 * draft/archived companies never opened a real verification_requests row
 * and get none here either, matching the mirror's "opens on real submit"
 * semantics. suspended companies are mirrored at their last real stage
 * (verified), since the mirror has no suspended equivalent (see this
 * item's plan, Scope Decision) — this is a deliberate, documented
 * approximation, not a bug.
 */
class BackfillCompanyVerificationMirror extends Command
{
    protected $signature = 'companies:backfill-verification-mirror';

    protected $description = 'Backfill read-only Verification mirror rows for existing companies (gap-plan 0.2b v2)';

    public function handle(CompanyVerificationMirror $mirror): int
    {
        $actor = User::query()->first();

        if (! $actor) {
            $this->error('No User exists to attribute backfilled checkpoints to.');

            return self::FAILURE;
        }

        Company::query()
            ->whereIn('status', [
                CompanyStatus::Pending->value,
                CompanyStatus::Verified->value,
                CompanyStatus::Rejected->value,
                CompanyStatus::Suspended->value,
            ])
            ->chunkById(200, function ($companies) use ($mirror, $actor) {
                foreach ($companies as $company) {
                    $mirror->submitted($company);

                    if (in_array($company->status, [CompanyStatus::Verified, CompanyStatus::Suspended], true)) {
                        $mirror->approved($company, $actor);
                    } elseif ($company->status === CompanyStatus::Rejected) {
                        $mirror->rejected($company, $actor);
                    }
                }
            });

        $this->info('Company verification mirror backfill complete.');

        return self::SUCCESS;
    }
}
