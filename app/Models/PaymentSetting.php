<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per payment provider. The provider's credentials are stored as a
 * single encrypted JSON blob (`encrypted:array` cast) and are only ever
 * written by App\Actions\Payments\ApprovePaymentCredentialChange — never
 * through a plain Filament form field, so there is no "just paste the key
 * here" shortcut that skips the two-person + fresh-2FA gate. Mirrors
 * App\Models\AiSetting.
 */
class PaymentSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_live' => 'boolean',
        ];
    }

    /**
     * Credential keys that must all be present for a provider to count as
     * configured. Matches config/payments.php's per-provider blocks.
     *
     * @var array<string, list<string>>
     */
    public const REQUIRED_KEYS = [
        'mtn_momo' => ['subscription_key', 'api_user', 'api_key'],
        'orange_money' => ['client_id', 'client_secret', 'merchant_key'],
        'stripe' => ['secret_key'],
        'paypal' => ['client_id', 'client_secret'],
    ];

    public function updatedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function credential(string $key): ?string
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return filled($value) ? (string) $value : null;
    }

    public function isConfigured(): bool
    {
        if (! $this->is_live) {
            return false;
        }

        $required = self::REQUIRED_KEYS[$this->provider] ?? [];

        foreach ($required as $key) {
            if (blank($this->credential($key))) {
                return false;
            }
        }

        return $required !== [];
    }

    public static function resolvedFor(PaymentProvider $provider): ?self
    {
        return self::query()->where('provider', $provider->value)->first();
    }

    public static function forProvider(PaymentProvider $provider): self
    {
        return self::firstOrCreate(
            ['provider' => $provider->value],
            ['environment' => 'sandbox', 'is_live' => false],
        );
    }
}
