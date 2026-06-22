<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompanyDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'visibility' => DocumentVisibility::class,
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'reviewed_at' => 'datetime',
            'sigif_fields' => 'array',
            'file_size' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(DocumentAccessLog::class);
    }

    public function reminderLogs(): HasMany
    {
        return $this->hasMany(DocumentReminderLog::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Pending->value);
    }

    /** Approved and not expired. */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Approved->value)
            ->where(fn (Builder $q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', today()));
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }
}
