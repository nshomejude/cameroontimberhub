<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Support\CategoryMigrationMap;
use Illuminate\Console\Command;

/**
 * Backfills products.category_id from products.product_type using
 * CategoryMigrationMap::MAP. Idempotent and safe to re-run: it only ever
 * writes to rows where `category_id IS NULL`, so it never overwrites a
 * value set by hand or by a previous run.
 */
class BackfillProductCategories extends Command
{
    protected $signature = 'products:backfill-categories';

    protected $description = 'Backfill products.category_id from products.product_type via CategoryMigrationMap.';

    public function handle(): int
    {
        $categoryIdsBySlug = Category::query()->where('kind', 'form')->pluck('id', 'slug');

        $total = 0;

        foreach (CategoryMigrationMap::MAP as $productType => $categorySlug) {
            $categoryId = $categoryIdsBySlug[$categorySlug] ?? null;

            if ($categoryId === null) {
                $this->warn("Skipping product_type={$productType}: category slug '{$categorySlug}' not found.");

                continue;
            }

            $updated = Product::query()
                ->where('product_type', $productType)
                ->whereNull('category_id')
                ->update(['category_id' => $categoryId]);

            $this->info("Backfilled {$updated} product".($updated === 1 ? '' : 's')." with product_type={$productType} to category={$categorySlug}.");

            $total += $updated;
        }

        $uncategorized = Product::query()->whereNull('category_id')->count();

        $this->info("Done. {$total} products backfilled. {$uncategorized} products remain uncategorized.");

        return self::SUCCESS;
    }
}
