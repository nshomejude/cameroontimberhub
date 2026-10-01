<?php

namespace App\Enums;

/**
 * Lifecycle of a monthly marketplace-commission statement
 * (App\Models\CommissionStatement). Mirrors the
 * `commission_statements_status_check` CHECK constraint.
 *
 * issued -> partially_paid -> paid, with `overdue` replacing issued /
 * partially_paid once the due date passes unpaid (amount_paid still tells
 * whether anything was paid), and `void` for a statement withdrawn by finance.
 */
enum CommissionStatementStatus: string
{
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Issued',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::Void => 'Void',
        };
    }

    /** Filament badge color key. */
    public function color(): string
    {
        return match ($this) {
            self::Issued => 'info',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
            self::Overdue => 'danger',
            self::Void => 'gray',
        };
    }

    /** Still owed money (a deposit can be reported against it). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Issued, self::PartiallyPaid, self::Overdue], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Issued->value, self::PartiallyPaid->value, self::Overdue->value];
    }
}
