<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Builds a product's public identifier: `CTH-CMR-{LLL}-{NNNNN}` where `LLL`
 * is three uppercase A-Z letters derived from the linked species' common
 * name (`XXX` when there is no species) and `NNNNN` is a zero-padded,
 * per-letter-segment sequence.
 *
 * Collision-safe: the counter row in `product_public_id_sequences` is taken
 * with `lockForUpdate` inside a transaction, mirroring the locked-sequence
 * discipline of RfqReferenceGenerator.
 *
 * Idempotent: a product that already has a `public_id` keeps it — this never
 * re-allocates for the same row.
 */
class ProductIdentifier
{
    public static function forProduct(Product $product): string
    {
        if (filled($product->public_id)) {
            return (string) $product->public_id;
        }

        $letters = self::letterSegment($product);

        return DB::transaction(function () use ($letters): string {
            $current = (int) (DB::table('product_public_id_sequences')
                ->where('letters', $letters)
                ->lockForUpdate()
                ->value('last_value') ?? 0);

            $next = $current + 1;

            DB::table('product_public_id_sequences')->upsert(
                [['letters' => $letters, 'last_value' => $next, 'created_at' => now(), 'updated_at' => now()]],
                ['letters'],
                ['last_value' => $next, 'updated_at' => now()],
            );

            return sprintf('CTH-CMR-%s-%05d', $letters, $next);
        });
    }

    /**
     * Three uppercase letters from the species common name. Reads the FK and
     * queries `species` directly rather than touching `$product->species` so
     * it is safe under Model::preventLazyLoading().
     */
    private static function letterSegment(Product $product): string
    {
        $speciesId = $product->getAttribute('species_id');

        $name = $speciesId
            ? DB::table('species')->where('id', $speciesId)->value('common_name')
            : null;

        $alpha = strtoupper((string) preg_replace('/[^A-Za-z]/', '', (string) $name));

        if ($alpha === '') {
            return 'XXX';
        }

        return str_pad(substr($alpha, 0, 3), 3, 'X');
    }
}
