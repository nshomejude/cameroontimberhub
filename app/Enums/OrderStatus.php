<?php

namespace App\Enums;

/**
 * Lifecycle of an order, created when a buyer awards a quote.
 *
 * Mirrors the `orders_status_check` CHECK constraint exactly.
 */
enum OrderStatus: string
{
    case Awarded = 'awarded';
    case Confirmed = 'confirmed';
    case InProduction = 'in_production';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Awarded => 'Awarded',
            self::Confirmed => 'Confirmed',
            self::InProduction => 'In production',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** What has actually happened — shown to the buyer, so it must be literal. */
    public function description(): string
    {
        return match ($this) {
            self::Awarded => 'You have awarded this request. The supplier has been notified and has not confirmed yet.',
            self::Confirmed => 'The supplier has confirmed the order.',
            self::InProduction => 'The supplier has started preparing the goods.',
            self::Shipped => 'The supplier has recorded the goods as shipped.',
            self::Delivered => 'Delivery has been recorded at the destination.',
            self::Completed => 'The order is closed. Both sides consider it finished.',
            self::Cancelled => 'This order was cancelled.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Awarded => 'info',
            self::Confirmed => 'success',
            self::InProduction, self::Shipped => 'warning',
            self::Delivered => 'success',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** True once the order can no longer change. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /** Ordered milestones, for the buyer-facing progress trail. */
    public static function milestones(): array
    {
        return [self::Awarded, self::Confirmed, self::InProduction, self::Shipped, self::Delivered, self::Completed];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
