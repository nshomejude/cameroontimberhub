<?php

namespace App\Exceptions\Api;

/**
 * 409 `commission_overdue`: optional enforcement (config
 * `timber.commission.block_on_overdue_days`, OFF by default) — the company
 * has a commission statement unpaid more than N days past its due date, so it
 * cannot submit new quotes until the deposit is confirmed by finance.
 *
 * Thrown from the domain path itself
 * (App\Services\Commission\CommissionCollectionService::assertMayQuote(),
 * called by QuoteService::assertQuotable()), so every quote path is covered;
 * on the web it surfaces as a plain RuntimeException message, on the API the
 * standard error envelope renders it as a 409.
 */
class CommissionOverdueException extends ConflictException
{
    public const CODE = 'commission_overdue';

    public function __construct(string $message)
    {
        parent::__construct($message, self::CODE);
    }
}
