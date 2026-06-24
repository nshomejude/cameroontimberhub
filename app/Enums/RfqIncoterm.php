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
        return match ($this) {
            self::EXW => 'EXW — Ex Works',
            self::FOB => 'FOB — Free on Board',
            self::CFR => 'CFR — Cost and Freight',
            self::CIF => 'CIF — Cost, Insurance and Freight',
            self::DAP => 'DAP — Delivered at Place',
        };
    }
}
