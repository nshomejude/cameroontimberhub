<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'renews_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'grace_until' => 'datetime',
            'renewal_reminded_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'price_amount' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Active->value);
    }

    /** In an opt-in trial window that has not yet elapsed. */
    public function onTrial(): bool
    {
        return $this->trial_ends_at?->isFuture() ?? false;
    }

    /** `past_due` but still inside the retained-entitlement grace window. */
    public function inGrace(): bool
    {
        return $this->status === SubscriptionStatus::PastDue
            && ($this->grace_until?->isFuture() ?? false);
    }

    /**
     * The load-bearing entitlement predicate. True while the status grants
     * entitlements AND — for `past_due` — grace has not run out. A `past_due`
     * sub with no `grace_until`, or one past it, is NOT entitled.
     */
    public function entitled(): bool
    {
        if (! $this->status->isEntitled()) {
            return false;
        }

        if ($this->status === SubscriptionStatus::PastDue) {
            return $this->inGrace();
        }

        return true;
    }
}
