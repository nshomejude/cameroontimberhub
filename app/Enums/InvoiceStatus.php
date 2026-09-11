<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Issued = 'issued';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return __('messages.enums.invoice_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Issued => 'info',
            self::Void => 'danger',
        };
    }
}
