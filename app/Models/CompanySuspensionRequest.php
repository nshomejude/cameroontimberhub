<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Two-person control (blueprint §88-89) for suspending a company. A request
 * starts `pending`; a DIFFERENT staff member than `requested_by` must
 * approve it (`approved_by`) before the company is actually transitioned to
 * Suspended. See App\Actions\Company\RequestCompanySuspension and
 * App\Actions\Company\ApproveCompanySuspension. Mirrors
 * VerificationRevocationRequest's structure.
 */
class CompanySuspensionRequest extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
