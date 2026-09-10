<?php

namespace App\Enums;

/**
 * Form (processing state) of a timber product: used in RFQ line items
 * and on company-species records to describe what a company can supply.
 */
enum TimberForm: string
{
    case Logs    = 'logs';
    case Sawn    = 'sawn';
    case Veneer  = 'veneer';
    case Plywood = 'plywood';
    case Other   = 'other';

    public function label(): string
    {
        return __('messages.enums.timber_form.'.$this->value);
    }
}
