<?php

namespace App\Enums;

enum RfqIncoterm: string
{
    case EXW = 'EXW';
    case FOB = 'FOB';
    case CFR = 'CFR';
    case CIF = 'CIF';
    case DAP = 'DAP';

    public function label(): string
    {
        return __('messages.enums.rfq_incoterm.'.$this->value);
    }
}
