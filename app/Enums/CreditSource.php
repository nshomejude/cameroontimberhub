<?php

namespace App\Enums;

/**
 * Where a `Credit` ledger row came from (billing engine M8, plan §19).
 */
enum CreditSource: string
{
    case AdminGrant = 'admin_grant';
    case Referral = 'referral';
    case Goodwill = 'goodwill';

    public function label(): string
    {
        return __('messages.enums.credit_source.'.$this->value);
    }
}
