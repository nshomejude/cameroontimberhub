<?php

namespace App\Enums;

enum CreditNoteStatus: string
{
    case Issued = 'issued';
    case Void = 'void';

    public function label(): string
    {
        return __('messages.enums.credit_note_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Issued => 'success',
            self::Void => 'danger',
        };
    }
}
