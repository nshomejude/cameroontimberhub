<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable record of a coupon being redeemed by a company (billing
 * engine M8). `amount_discounted`/`currency` are a snapshot taken at
 * redeem time — a later change to the coupon's `value` never alters this
 * row (plan §2 historical immutability).
 */
class CouponRedemption extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_discounted' => 'decimal:2',
            'redeemed_at' => 'datetime',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
