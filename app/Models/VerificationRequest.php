<?php

namespace App\Models;

use App\Enums\VerificationRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationRequest extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => VerificationRequestStatus::class,
            'decided_at' => 'datetime',
            'document_snapshot' => 'array',
            'requested_badges' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            VerificationRequestStatus::Pending->value,
            VerificationRequestStatus::InReview->value,
        ]);
    }
}
