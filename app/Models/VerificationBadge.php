<?php

namespace App\Models;

use App\Enums\BadgeStatus;
use App\Enums\BadgeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationBadge extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'badge_type' => BadgeType::class,
            'status' => BadgeStatus::class,
            'issued_at' => 'datetime',
            'valid_until' => 'date',
            'revoked_at' => 'datetime',
            'is_public' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function verificationRequest(): BelongsTo
    {
        return $this->belongsTo(VerificationRequest::class);
    }

    public function supportingDocument(): BelongsTo
    {
        return $this->belongsTo(CompanyDocument::class, 'supporting_document_id');
    }

    /** Active and within its validity window. */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', BadgeStatus::Active->value)
            ->where(fn (Builder $e) => $e->whereNull('valid_until')->orWhere('valid_until', '>', now()));
    }
}
