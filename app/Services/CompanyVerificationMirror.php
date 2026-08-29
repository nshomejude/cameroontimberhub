<?php

namespace App\Services;

use App\Enums\VerificationStage;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A READ-ONLY mirror of Company's real compliance-review state
 * (companies.status + verification_requests, both unchanged and still
 * authoritative — see docs/superpowers/plans/2026-08-29-verification-consumer-migration-v2.md)
 * into the polymorphic Verification framework from item 0.2, for future
 * cross-entity reporting only. Every method here is called AFTER the real
 * transition has already succeeded, and every method swallows its own
 * failures — a broken mirror must never block or reverse a real company
 * status change or a real badge issuance.
 *
 * Deliberately has no suspended()/archived()/resubmitted() methods:
 * VerificationStage has no equivalent stages for those (see this plan's
 * Scope Decision) so there is nothing correct to mirror them to yet.
 */
class CompanyVerificationMirror
{
    public function __construct(private readonly VerificationFlowService $flow) {}

    public function submitted(Company $company): void
    {
        $this->safely(function () use ($company) {
            $this->flow->open($company);
        });
    }

    public function approved(Company $company, User $actor): void
    {
        $this->safely(function () use ($company, $actor) {
            $verification = $this->flow->open($company);

            if ($verification->stage === VerificationStage::Verified) {
                return;
            }

            $this->flow->fastForward($verification, VerificationStage::Verified, $actor, 'Mirrored: company verification approved (verification_requests / VerifyCompany)');
        });
    }

    public function rejected(Company $company, User $actor): void
    {
        $this->safely(function () use ($company, $actor) {
            $verification = $this->flow->open($company);

            $this->flow->fastForward($verification, VerificationStage::Rejected, $actor, 'Mirrored: company verification rejected');
        });
    }

    protected function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            Log::warning('CompanyVerificationMirror write failed; real Company verification state is unaffected.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
