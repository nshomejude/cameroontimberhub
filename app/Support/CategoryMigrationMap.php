<?php

namespace App\Support;

/**
 * The single source of truth for translating existing App\Enums\ProductType
 * values (the processing FORM a listing is traded in) to the new top-level
 * `form`-kind App\Models\Category slugs, during the additive migration
 * described in docs/superpowers/plans/2026-08-29-category-tree.md.
 *
 * Every ProductType case has an entry — the mapping is unambiguous because
 * ProductType is itself already a "form" taxonomy, just flatter and more
 * granular than the six-group Category tree it now rolls up into.
 *
 * Sector-kind categories (Hospitality, Education, ...) are NOT covered here:
 * a product's sector is an independent, currently-manual facet with no
 * derivable source column — see the plan's Scope Decision section.
 *
 * When new ProductType cases are added, extend this array — that is the
 * ONLY file that needs a code change to pick up the new mapping;
 * App\Console\Commands\BackfillProductCategories already iterates this map
 * generically. Re-run `php artisan products:backfill-categories` afterwards;
 * it is idempotent and never overwrites a `category_id` that is already
 * set, so re-running after extending this map only fills in rows that are
 * still NULL.
 */
final class CategoryMigrationMap
{
    /**
     * @var array<string, string> ProductType value => Category slug (kind=form)
     */
    public const MAP = [
        'sawn_timber' => 'secondary-processed',
        'logs' => 'raw',
        'veneer' => 'secondary-processed',
        'flooring' => 'finished',
        'decking' => 'finished',
        'mouldings' => 'finished',
        'plywood' => 'secondary-processed',
        'beams' => 'construction',
        'planks' => 'secondary-processed',
        'boules' => 'raw',
        'squares' => 'secondary-processed',
        'sleepers' => 'construction',
        'poles' => 'construction',
        'slabs' => 'secondary-processed',
        'laminated_panels' => 'finished',
        'charcoal' => 'residue',
    ];
}
