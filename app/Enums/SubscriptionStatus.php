<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Trialing = 'trialing';
    case PastDue = 'past_due';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('messages.enums.subscription_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Trialing => 'info',
            self::PastDue => 'warning',
            self::Expired => 'gray',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Whether this status grants plan entitlements at all. `PastDue` is entitled
     * here because the grace window is real; the caller is responsible for
     * checking `grace_until` to decide when grace has run out.
     */
    public function isEntitled(): bool
    {
        return match ($this) {
            self::Active, self::Trialing, self::PastDue => true,
            self::Expired, self::Cancelled => false,
        };
    }
}
