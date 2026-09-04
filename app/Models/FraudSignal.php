<?php

namespace App\Models;

use App\Enums\FraudSignalSeverity;
use App\Enums\FraudSignalStatus;
use App\Enums\FraudSignalType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A reviewable anti-fraud flag (blueprint §25) raised by
 * FraudDetectionService against a polymorphic subject (Company, Product,
 * User, ...). Detection-and-alerting only: nothing in the app reads this
 * model to auto-block or auto-suspend anything — resolution is always a
 * human admin action via the FraudSignals Filament resource.
 */
class FraudSignal extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'signal_type' => FraudSignalType::class,
            'severity' => FraudSignalSeverity::class,
            'status' => FraudSignalStatus::class,
            'details' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', FraudSignalStatus::Open->value);
    }

    /** Marks this signal reviewed by an admin, moving it out of the open queue. */
    public function markReviewed(User $reviewer, FraudSignalStatus $status): void
    {
        $this->update([
            'status' => $status,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
        ]);
    }
}
