<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\ProductIdentifier;
use Illuminate\Console\Command;

/**
 * Assigns `products.public_id` to every listing that is missing one, in id
 * order so the per-species sequence is stable and reproducible.
 *
 * Idempotent and safe to re-run: only rows with `public_id IS NULL` are
 * touched, so a second run assigns nothing and every existing id is left
 * exactly as it was.
 */
class BackfillProductPublicIdsCommand extends Command
{
    protected $signature = 'products:backfill-public-ids';

    protected $description = 'Backfill products.public_id (CTH-CMR-…) for every listing missing one.';

    public function handle(): int
    {
        $assigned = 0;

        Product::withTrashed()
            ->whereNull('public_id')
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$assigned): void {
                foreach ($products as $product) {
                    $product->public_id = ProductIdentifier::forProduct($product);
                    $product->saveQuietly();
                    $assigned++;
                }
            });

        $this->info("Assigned {$assigned} product public id".($assigned === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
