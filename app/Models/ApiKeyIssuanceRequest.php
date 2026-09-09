<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class ApiKeyIssuanceRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requested_abilities' => 'array',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

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
        return $this->status === 'pending' && $this->expires_at?->isFuture();
    }

    public function inviteTokenMatches(string $plainToken): bool
    {
        return Hash::check($plainToken, $this->invite_token_hash);
    }
}
