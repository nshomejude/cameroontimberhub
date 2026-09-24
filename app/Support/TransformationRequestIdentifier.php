<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Builds a transformation request's reference code: `CTH-XFR-{NNNNNN}`, a
 * globally monotonic, zero-padded sequence — mirrors
 * App\Support\CarbonProjectIdentifier's locked-single-row counter pattern
 * (`transformation_request_reference_sequences`) rather than hand-rolled
 * string formatting inline.
 */
class TransformationRequestIdentifier
{
    public static function next(): string
    {
        return DB::transaction(function (): string {
            $row = DB::table('transformation_request_reference_sequences')->lockForUpdate()->first();

            if ($row === null) {
                $next = 1;
                DB::table('transformation_request_reference_sequences')->insert(['last_value' => $next]);
            } else {
                $next = (int) $row->last_value + 1;
                DB::table('transformation_request_reference_sequences')
                    ->where('id', $row->id)
                    ->update(['last_value' => $next]);
            }

            return sprintf('CTH-XFR-%06d', $next);
        });
    }
}
