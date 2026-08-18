<?php

namespace App\Services;

use App\Models\Quote;

/**
 * Quote reference codes — same shape and alphabet as RfqReferenceGenerator,
 * different prefix so a reference is unambiguous wherever it is quoted.
 */
class QuoteReferenceGenerator
{
    /** Crockford base32 without ambiguous chars (no I, L, O, U). */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function generate(): string
    {
        $year = now()->year;

        do {
            $reference = "QTE-{$year}-".$this->suffix();
        } while (Quote::withTrashed()->where('reference_code', $reference)->exists());

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
