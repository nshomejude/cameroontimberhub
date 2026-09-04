<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Two-person control (blueprint §89) for revoking a company's verification.
 * A request starts `pending`; a DIFFERENT staff member than `requested_by`
 * must approve it (`approved_by`) before the company's active verification
 * badges are actually revoked. See
 * App\Actions\Verification\RequestVerificationRevocation and
 * App\Actions\Verification\ApproveVerificationRevocation.
 */
class VerificationRevocationRequest extends Model
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
