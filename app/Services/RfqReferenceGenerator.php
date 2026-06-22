<?php

namespace App\Services;

use App\Models\Rfq;

class RfqReferenceGenerator
{
    /** Crockford base32 without ambiguous chars (no I, L, O, U). */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function generate(): string
    {
        $year = now()->year;

        do {
            $reference = "RFQ-{$year}-".$this->suffix();
        } while (Rfq::withTrashed()->where('reference_code', $reference)->exists());

        return $reference;
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
