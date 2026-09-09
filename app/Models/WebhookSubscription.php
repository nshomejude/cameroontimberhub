<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A company-owned webhook subscription (architecture plan, Task 0.5). The
 * plaintext secret is never stored — only `secret_hash` — and is shown to
 * the company exactly once, at creation time, the same UX as an API key
 * (see App\Filament\Resources\ApiKeyIssuanceRequests).
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

    /** Generates a new plaintext secret and returns it; caller is responsible for storing the hash and showing the plaintext once. */
    public static function generatePlainTextSecret(): string
    {
        return Str::random(40);
    }

    public static function hashSecret(string $plainTextSecret): string
    {
        return hash('sha256', $plainTextSecret);
    }
}
