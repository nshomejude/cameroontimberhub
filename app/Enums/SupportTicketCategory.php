<?php

namespace App\Enums;

enum SupportTicketCategory: string
{
    case Account = 'account';
    case Order = 'order';
    case Payment = 'payment';
    case Verification = 'verification';
    case Listing = 'listing';
    case Technical = 'technical';
    case Other = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
