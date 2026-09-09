<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery-attempt-cycle row for a webhook subscription (architecture
 * plan, Task 0.5). See the migration doc block for the terminal-state
 * invariant (delivered_at XOR failed_permanently_at, never both).
 */
class WebhookDelivery extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
            'failed_permanently_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }

    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }

    public function isFailedPermanently(): bool
    {
        return $this->failed_permanently_at !== null;
    }
}
