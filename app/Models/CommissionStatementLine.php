<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a CommissionStatement.
 *
 * - `charge`: an order's net commission (charged minus credited at issue
 *   time). Billed once: a partial unique index allows one non-void charge
 *   line per order; voiding the statement stamps `voided_at`, releasing the
 *   order for the next statement run.
 * - `adjustment`: a credit issued AFTER the order was billed (refund,
 *   dispute, cancellation) — a negative amount carried onto the next
 *   statement (PRICING_SPEC §15 collection note).
 */
class CommissionStatementLine extends Model
{
    public const KIND_CHARGE = 'charge';

    public const KIND_ADJUSTMENT = 'adjustment';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'charged_at' => 'datetime',
            'voided_at' => 'datetime',
            'order_subtotal' => 'decimal:2',
            'commission_rate' => 'decimal:4',
            'commission_amount' => 'decimal:2',
            'credited_amount' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CommissionStatement::class, 'commission_statement_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isAdjustment(): bool
    {
        return $this->kind === self::KIND_ADJUSTMENT;
    }
}
