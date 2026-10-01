<?php

namespace App\Enums;

/**
 * How a supplier paid its commission to the platform (owner decision
 * 2026-10-01: manual deposit only, no automatic debit). Mirrors
 * `commission_deposits_method_check`.
 */
enum CommissionDepositMethod: string
{
    case MtnMomo = 'mtn_momo';
    case OrangeMoney = 'orange_money';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::MtnMomo => 'MTN Mobile Money',
            self::OrangeMoney => 'Orange Money',
            self::Bank => 'Bank deposit / transfer',
        };
    }

    /** @return array<string, string> value => label, for selects. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $m) => [$m->value => $m->label()])->all();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
