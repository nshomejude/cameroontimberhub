<?php

namespace App\Models;

use App\Enums\AiProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * One row per AI provider. The API key is stored encrypted at rest and is
 * only ever written by App\Actions\Ai\ApproveAiApiKeyChange — never through
 * a plain Filament form field, so there is no "just paste your key here"
 * shortcut that skips the two-person + 2FA gate.
 */
class AiSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'is_active' => 'boolean',
            'key_updated_at' => 'datetime',
        ];
    }

    public function updatedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function hasKey(): bool
    {
        return filled($this->api_key_encrypted);
    }

    public function decryptedApiKey(): ?string
    {
        if (! $this->hasKey()) {
            return null;
        }

        try {
            return Crypt::decryptString($this->api_key_encrypted);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return null;
        }
    }

    public static function forProvider(AiProvider $provider): self
    {
        return self::firstOrCreate(['provider' => $provider->value], [
            'model' => $provider->defaultModel(),
            'is_active' => $provider === AiProvider::Claude,
        ]);
    }
}
