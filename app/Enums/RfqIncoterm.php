<?php

namespace App\Enums;

enum RfqIncoterm: string
{
    case EXW = 'EXW';
    case FOB = 'FOB';
    case CFR = 'CFR';
    case CIF = 'CIF';
    case DAP = 'DAP';
    // The governing CHECK constraint on rfqs/quotes/orders has always
    // permitted 'other'; the enum simply lacked the case (standard §G1).
    case Other = 'other';

    public function label(): string
    {
        return __('messages.enums.rfq_incoterm.'.$this->value);
    }
}
