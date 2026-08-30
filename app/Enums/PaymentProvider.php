<?php

namespace App\Enums;

enum PaymentProvider: string
{
    case MtnMomo = 'mtn_momo';
    case OrangeMoney = 'orange_money';
    case Stripe = 'stripe';
    case PayPal = 'paypal';

    public function label(): string
    {
        return match ($this) {
            self::MtnMomo => 'MTN Mobile Money',
            self::OrangeMoney => 'Orange Money',
            self::Stripe => 'Stripe',
            self::PayPal => 'PayPal',
        };
    }
}
