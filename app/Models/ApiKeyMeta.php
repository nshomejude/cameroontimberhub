<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Additive companion row to a Sanctum `personal_access_tokens` record —
 * see the migration doc block for why this exists instead of a parallel
 * `api_keys` table. Sanctum owns the token hash/name/abilities; this owns
 * company scoping, the rate-limit tier and the two-person issuance trail.
 */
class ApiKeyMeta extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
        ];
    }

    public function personalAccessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class);
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

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
