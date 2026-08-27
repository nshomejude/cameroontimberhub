<?php

namespace App\Models;

use App\Enums\CheckpointStatus;
use App\Enums\VerificationStage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reviewed request against a Verification at a given stage. A Verification
 * accumulates one or more checkpoints per stage as it is submitted, sent back
 * for more info, resubmitted, and finally approved or rejected.
 */
class VerificationCheckpoint extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stage' => VerificationStage::class,
            'status' => CheckpointStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
