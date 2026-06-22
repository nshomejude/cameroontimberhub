<?php

namespace App\Enums;

enum DocumentVisibility: string
{
    case Private = 'private';
    case AdminOnly = 'admin_only';
    case BuyerVisible = 'buyer_visible';
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Private',
            self::AdminOnly => 'Admin only',
            self::BuyerVisible => 'Buyer-visible',
            self::Public => 'Public',
        };
    }
}
