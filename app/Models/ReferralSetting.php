<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton row holding the admin-configurable referral programme terms
 * (edited from /admin → Referral settings). `current()` lazily creates the
 * row with the owner-decided defaults: enabled, 10% of the referred
 * company's subscription payment, paid once (first payment only).
 */
class ReferralSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'rate_percent' => 'decimal:2',
            'one_time' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return self::query()->orderBy('id')->first()
            ?? self::create(['enabled' => true, 'rate_percent' => 10, 'basis' => 'subscription', 'one_time' => true]);
    }
}
