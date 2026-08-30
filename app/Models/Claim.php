<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A registered public marketing claim (implementation blueprint §48).
 *
 * Every material public claim (verified supplier counts, RFQ volumes,
 * environmental/compliance/certification claims, etc.) should be tracked
 * here with its evidence source, owner, and a review cadence, so the
 * platform never publishes an unsubstantiated claim by accident.
 */
class Claim extends Model
{
    protected $table = 'claims_register';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'approved_at' => 'date',
            'review_date' => 'date',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Claims that need staff attention right now: explicitly flagged
     * `needs_review`, or `approved` but overdue for their scheduled
     * re-review (`review_date` in the past). This is the practical value of
     * the register — surfacing what needs attention, not just storing text.
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status', 'needs_review')
                ->orWhere(function (Builder $q2) {
                    $q2->where('status', 'approved')
                        ->whereNotNull('review_date')
                        ->whereDate('review_date', '<', Carbon::today());
                });
        });
    }
}
