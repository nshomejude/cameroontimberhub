<?php

namespace App\Models;

use App\Enums\CheckpointStatus;
use App\Enums\VerificationStage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The polymorphic verification workflow for any entity (Product today;
 * carbon projects, vehicles, artisans as those land — see gap-plan 0.2 and
 * `CTH_Claude_Code_Build_Brief.md` §3.4).
 *
 * `stage` tracks position in the 8-step sequence
 * (registered -> company_info -> business_docs -> identity_kyc ->
 * forestry_legal_docs -> compliance_review -> verified -> published), with
 * `rejected` as a terminal branch off compliance_review. Mutation goes
 * through VerificationFlowService, never direct assignment here — see that
 * class's TRANSITIONS map for the legal-transition table.
 *
 * `needs_more_info` is NOT a stage value on this model. It is a
 * VerificationCheckpoint::status outcome recorded against the *current*
 * stage without moving `stage` itself — see needsMoreInfo() below, which
 * reads the latest checkpoint rather than any column on this row.
 */
class Verification extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stage' => VerificationStage::class,
            'published_at' => 'datetime',
        ];
    }

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(VerificationCheckpoint::class);
    }

    /**
     * True when the most recent checkpoint at the current stage asked for
     * more information and no newer checkpoint has superseded it. This is
     * how gap-plan 0.2's "needs_more_info at request level" is surfaced
     * without it ever becoming a Verification::stage value.
     */
    public function needsMoreInfo(): bool
    {
        $latest = $this->checkpoints()
            ->where('stage', $this->stage->value)
            ->latest('id')
            ->first();

        return $latest?->status === CheckpointStatus::NeedsMoreInfo;
    }
}
