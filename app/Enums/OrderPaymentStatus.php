<?php

namespace App\Enums;

/**
 * Settlement state of an order.
 *
 * The platform processes no payments. Every value here is recorded by platform
 * staff from evidence supplied off-platform; nothing sets it automatically, and
 * the default is always `Unpaid`. The buyer-facing copy says so explicitly.
 *
 * Mirrors the `orders_payment_status_check` CHECK constraint exactly.
 */
enum OrderPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'No payment recorded',
            self::PartiallyPaid => 'Part payment recorded',
            self::Paid => 'Paid in full',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'gray',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
