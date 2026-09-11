<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An admin-configurable discount code (billing engine M8, plan §19).
 *
 * Stacking is off by default (`stacks_with_annual`) and a coupon can never
 * reduce mandatory tax — App\Services\Billing\CouponCalculator applies a
 * coupon's discount to the subtotal only, before tax is computed.
 *
 * NOT wired into checkout yet — this is intentionally deferred (see
 * CouponCalculator docblock). `redemptions_count` is a running counter for
 * a fast cap check; the authoritative history is `coupon_redemptions`.
 */
class Coupon extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'applies_to_segments' => 'array',
            'applies_to_plan_ids' => 'array',
            'max_redemptions' => 'integer',
            'redemptions_count' => 'integer',
            'max_redemptions_per_company' => 'integer',
            'stacks_with_annual' => 'boolean',
            'is_active' => 'boolean',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Coupon $coupon): void {
            $coupon->code = strtoupper(trim((string) $coupon->code));
            $coupon->created_by ??= auth()->id();
        });
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Coupons that are switched on AND whose validity window contains $at
     * (default: now) AND still have redemptions left. Mirrors
     * TaxRule::scopeActive.
     */
    public function scopeActive(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        $at = $at ? \Illuminate\Support\Carbon::instance($at) : now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $at))
            ->where(fn (Builder $q) => $q->whereNull('max_redemptions')->orWhereColumn('redemptions_count', '<', 'max_redemptions'));
    }

    /**
     * Whether this coupon's segment/plan filters allow $plan. Null filters
     * mean "all" at that level.
     */
    public function appliesTo(Plan $plan): bool
    {
        if (is_array($this->applies_to_segments) && $this->applies_to_segments !== [] && ! in_array($plan->segment, $this->applies_to_segments, true)) {
            return false;
        }

        if (is_array($this->applies_to_plan_ids) && $this->applies_to_plan_ids !== [] && ! in_array($plan->id, $this->applies_to_plan_ids, true)) {
            return false;
        }

        return true;
    }

    public function isPercent(): bool
    {
        return $this->type === 'percent';
    }

    public function isFixed(): bool
    {
        return $this->type === 'fixed';
    }
}
