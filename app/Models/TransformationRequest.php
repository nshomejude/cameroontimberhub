<?php

namespace App\Models;

use App\Enums\TransformationRequestStatus;
use App\Support\TransformationRequestIdentifier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A transformation-service request from one company (requester — buyer,
 * supplier or retailer) to another (provider — verified processor or
 * manufacturer). See App\Services\TransformationRequestService for the
 * status machine and App\Enums\TransformationRequestStatus for the legal
 * transition table.
 */
class TransformationRequest extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'reference_code';
    }

    protected function casts(): array
    {
        return [
            'status' => TransformationRequestStatus::class,
            'volume_m3' => 'decimal:3',
            'quote_amount' => 'decimal:2',
            'quote_lead_time_days' => 'integer',
            'deadline' => 'date',
            'timeline' => 'array',
            'accepted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TransformationRequest $request): void {
            if (! $request->reference_code) {
                $request->reference_code = TransformationRequestIdentifier::next();
            }
        });
    }

    public function requesterCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'requester_company_id');
    }

    public function providerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'provider_company_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    public function lotTransformation(): BelongsTo
    {
        return $this->belongsTo(LotTransformation::class);
    }

    /** Append one entry to the jsonb activity trail — see the migration's docblock for why jsonb over a child table. */
    public function appendTimeline(TransformationRequestStatus $status): void
    {
        $entries = $this->timeline ?? [];

        $entries[] = [
            'status' => $status->value,
            'label' => $status->label(),
            'at' => now()->toIso8601String(),
        ];

        $this->timeline = $entries;
    }
}
