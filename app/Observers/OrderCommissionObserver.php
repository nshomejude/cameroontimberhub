<?php

namespace App\Observers;

use App\Models\Order;
use RuntimeException;

/**
 * Guards the commission snapshot columns on `orders` (billing engine M7,
 * plan §2 historical immutability).
 *
 * Kept as its own observer (rather than a change to `Order`'s own code)
 * because this worktree shares `app/Models/Order.php` risk with unrelated
 * concurrent work — see the M7 task brief. Once
 * `App\Services\Commission\CommissionCalculator::charge()` has set
 * `is_commission_charged = true`, `commission_rate` / `commission_amount` /
 * `commission_rule_id` are frozen: a later `commission_rules` edit or
 * unrelated order update must never re-rate a past order. `charge()` itself
 * uses `forceFill()` + `save()`, which still runs through `updating()`, so
 * it deliberately writes those columns for the FIRST time before
 * `is_commission_charged` flips true in the same call — the guard only
 * fires once the original row already has `is_commission_charged = true`.
 */
class OrderCommissionObserver
{
    private const SNAPSHOT_COLUMNS = ['commission_rate', 'commission_amount', 'commission_rule_id'];

    public function updating(Order $order): void
    {
        if (! $order->getOriginal('is_commission_charged')) {
            return;
        }

        if ($order->isDirty(self::SNAPSHOT_COLUMNS)) {
            throw new RuntimeException(
                'The commission snapshot on a charged order cannot be edited in place — see '
                .'App\\Observers\\OrderCommissionObserver docblock.'
            );
        }
    }
}
