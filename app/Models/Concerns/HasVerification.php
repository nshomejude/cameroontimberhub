<?php

namespace App\Models\Concerns;

use App\Enums\VerificationStage;
use App\Models\Verification;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Gives a model a polymorphic `verification` relation over the shared
 * Verification workflow (gap-plan 0.2). A model can hold at most one OPEN
 * Verification at a time — see the partial unique index in
 * 2026_08_28_100020_create_verifications_table.php — so this is morphOne,
 * not morphMany, matching the "current verification state" mental model
 * (history lives on VerificationCheckpoint, not on multiple Verification
 * rows).
 */
trait HasVerification
{
    public function verification(): MorphOne
    {
        return $this->morphOne(Verification::class, 'entity')->latestOfMany();
    }

    public function isVerified(): bool
    {
        return in_array($this->verification?->stage, [VerificationStage::Verified, VerificationStage::Published], true);
    }
}
