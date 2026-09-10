<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A company-owned webhook subscription (architecture plan, Task 0.5).
 *
 * The signing secret is stored encrypted at rest in `secret` (Laravel's
 * `encrypted` cast → Crypt::encryptString, same at-rest posture as
 * App\Models\AiSetting) and the plaintext IS the HMAC key, Stripe/GitHub
 * `whsec_...` style. It is shown to the company exactly once, at creation
 * time — the same UX as an API key — and can be read back server-side only
 * to sign an outgoing delivery.
 */
class WebhookSubscription extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_types' => 'array',
            'is_active' => 'boolean',
            'secret' => 'encrypted',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id');
    }

    /** True if this subscription is active and subscribed to the given event type. */
    public function subscribesTo(string $eventType): bool
    {
        return $this->is_active && in_array($eventType, $this->event_types ?? [], true);
    }

    /**
     * Generates a new plaintext signing secret and returns it; the caller
     * stores it on `secret` (the `encrypted` cast handles encryption) and
     * shows the plaintext to the company exactly once.
     */
    public static function generatePlainTextSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }
}
