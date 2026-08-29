<?php

namespace App\Services;

use App\Enums\CheckpointStatus;
use App\Enums\VerificationStage;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The single mutation point for Verification::stage (gap-plan 0.2,
 * `CTH_Claude_Code_Build_Brief.md` §3.4). Mirrors CompanyStatusService's
 * TRANSITIONS-map pattern deliberately, so this reads the same way to anyone
 * who already knows that service — but drives an entirely separate,
 * standalone table (see this plan's "Scope decision").
 */
class VerificationFlowService
{
    /** @var array<string, string> forward transition on approval, keyed by current stage value */
    public const FORWARD = [
        'registered' => 'company_info',
        'company_info' => 'business_docs',
        'business_docs' => 'identity_kyc',
        'identity_kyc' => 'forestry_legal_docs',
        'forestry_legal_docs' => 'compliance_review',
        'compliance_review' => 'verified',
    ];

    public function open(Model $entity): Verification
    {
        $existing = Verification::query()
            ->where('entity_type', $entity::class)
            ->where('entity_id', $entity->getKey())
            ->whereNotIn('stage', [VerificationStage::Rejected->value, VerificationStage::Published->value])
            ->first();

        if ($existing) {
            return $existing;
        }

        return Verification::create([
            'entity_type' => $entity::class,
            'entity_id' => $entity->getKey(),
            'stage' => VerificationStage::Registered,
        ]);
    }

    public function approve(Verification $verification, User $actor, ?string $notes = null): Verification
    {
        $this->assertNotTerminal($verification);

        $target = self::FORWARD[$verification->stage->value] ?? null;

        if ($target === null) {
            throw new RuntimeException("No forward transition defined from stage {$verification->stage->value}.");
        }

        return DB::transaction(function () use ($verification, $target, $actor, $notes) {
            $verification->checkpoints()->create([
                'stage' => $verification->stage,
                'status' => CheckpointStatus::Approved,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'notes' => $notes,
            ]);

            $verification->update(['stage' => $target]);

            return $verification->fresh();
        });
    }

    public function reject(Verification $verification, string $reason, User $actor): Verification
    {
        $this->assertNotTerminal($verification);

        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required to reject a verification.']);
        }

        return DB::transaction(function () use ($verification, $reason, $actor) {
            $verification->checkpoints()->create([
                'stage' => $verification->stage,
                'status' => CheckpointStatus::Rejected,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'notes' => $reason,
            ]);

            $verification->update(['stage' => VerificationStage::Rejected]);

            return $verification->fresh();
        });
    }

    /**
     * Record that more information is needed at the CURRENT stage. This does
     * not move Verification::stage — see class docblock and
     * Verification::needsMoreInfo().
     */
    public function requestMoreInfo(Verification $verification, string $instructions, User $actor): Verification
    {
        $this->assertNotTerminal($verification);

        if (blank($instructions)) {
            throw ValidationException::withMessages(['instructions' => 'Instructions are required when requesting more information.']);
        }

        $verification->checkpoints()->create([
            'stage' => $verification->stage,
            'status' => CheckpointStatus::NeedsMoreInfo,
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
            'notes' => $instructions,
        ]);

        return $verification->fresh();
    }

    /**
     * The entity resubmits at the same stage after a needs_more_info
     * checkpoint. Records a fresh Pending checkpoint so the latest checkpoint
     * for the stage is no longer NeedsMoreInfo, clearing needsMoreInfo()
     * without touching `stage`.
     */
    public function resubmit(Verification $verification): Verification
    {
        $this->assertNotTerminal($verification);

        $verification->checkpoints()->create([
            'stage' => $verification->stage,
            'status' => CheckpointStatus::Pending,
        ]);

        return $verification->fresh();
    }

    public function publish(Verification $verification, User $actor): Verification
    {
        if ($verification->stage !== VerificationStage::Verified) {
            throw new RuntimeException('Only a verification at the verified stage can be published, current stage: '.$verification->stage->value);
        }

        return DB::transaction(function () use ($verification, $actor) {
            $verification->checkpoints()->create([
                'stage' => VerificationStage::Verified,
                'status' => CheckpointStatus::Approved,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ]);

            $verification->update([
                'stage' => VerificationStage::Published,
                'published_at' => now(),
            ]);

            return $verification->fresh();
        });
    }

    /**
     * Jump directly to a target stage in ONE checkpoint, for consumers whose
     * real review process has no distinct per-stage checkpoints of its own
     * (see docs/superpowers/plans/2026-08-29-verification-consumer-migration-v2.md
     * — Company's flat pending/in_review/approved/rejected workflow). Unlike
     * approve(), this does not require $target to be FORWARD's single next
     * hop; it still refuses a terminal source stage.
     */
    public function fastForward(Verification $verification, VerificationStage $target, User $actor, string $note): Verification
    {
        $this->assertNotTerminal($verification);

        return DB::transaction(function () use ($verification, $target, $actor, $note) {
            $verification->checkpoints()->create([
                'stage' => $verification->stage,
                'status' => $target === VerificationStage::Rejected ? CheckpointStatus::Rejected : CheckpointStatus::Approved,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'notes' => $note,
            ]);

            $verification->update([
                'stage' => $target,
                'published_at' => $target === VerificationStage::Published ? now() : $verification->published_at,
            ]);

            return $verification->fresh();
        });
    }

    protected function assertNotTerminal(Verification $verification): void
    {
        if ($verification->stage->isTerminal()) {
            throw new RuntimeException("Verification is in a terminal stage ({$verification->stage->value}) and cannot transition further.");
        }
    }
}
