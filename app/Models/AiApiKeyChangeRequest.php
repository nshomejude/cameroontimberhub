<?php

namespace App\Models;

use App\Enums\AiProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

class AiApiKeyChangeRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
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

    public function decryptedNewKey(): string
    {
        return Crypt::decryptString($this->new_api_key_encrypted);
    }

    public function inviteTokenMatches(string $plainToken): bool
    {
        return Hash::check($plainToken, $this->invite_token_hash);
    }
}
