<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * Mirrors App\Models\AiApiKeyChangeRequest. proposed_credentials holds the
 * new key/value pairs encrypted at rest; they are never applied until
 * App\Actions\Payments\ApprovePaymentCredentialChange runs (different
 * approver + one-time invite token + fresh 2FA).
 */
class PaymentCredentialChangeRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'proposed_credentials' => 'encrypted:array',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function requestedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
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
