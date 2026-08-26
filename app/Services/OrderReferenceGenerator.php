<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Receipt;
use Illuminate\Support\Str;

/**
 * Order and receipt reference codes — same shape and alphabet as
 * RfqReferenceGenerator, different prefixes so a reference is unambiguous
 * wherever it is quoted.
 *
 * Also mints receipt verification tokens. Those are *not* references: they are
 * 40 random characters with no year, no counter and no relationship to the
 * printed number, because they are the only thing standing between a stranger
 * and a receipt record.
 */
class OrderReferenceGenerator
{
    /** Crockford base32 without ambiguous chars (no I, L, O, U). */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function order(): string
    {
        $year = now()->year;

        do {
            $reference = "ORD-{$year}-".$this->suffix();
        } while (Order::where('reference_code', $reference)->exists());

        return $reference;
    }

    public function receipt(): string
    {
        $year = now()->year;

        do {
            $reference = "RCT-{$year}-".$this->suffix();
        } while (Receipt::where('receipt_number', $reference)->exists());

        return $reference;
    }

    /** Unguessable verification token. Never sequential, never derived. */
    public function verificationToken(): string
    {
        do {
            $token = Str::random(40);
        } while (Receipt::where('verification_token', $token)->exists());

        return $token;
    }

    private function suffix(): string
    {
        $suffix = '';
        for ($i = 0; $i < 5; $i++) {
            $suffix .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $suffix;
    }
}
