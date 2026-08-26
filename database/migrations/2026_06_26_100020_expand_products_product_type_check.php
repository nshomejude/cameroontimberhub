<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen the products.product_type CHECK constraint to cover the full range of
 * timber forms traded out of Cameroon (see App\Enums\ProductType).
 */
return new class extends Migration
{
    private const TYPES = [
        'sawn_timber', 'logs', 'veneer', 'flooring', 'decking', 'mouldings', 'plywood', 'beams',
        'planks', 'boules', 'squares', 'sleepers', 'poles', 'slabs', 'laminated_panels', 'charcoal',
    ];

    private const ORIGINAL_TYPES = [
        'sawn_timber', 'logs', 'veneer', 'flooring', 'decking', 'mouldings', 'plywood', 'beams',
    ];

    public function up(): void
    {
        $this->replaceConstraint(self::TYPES);
    }

    public function down(): void
    {
        $this->replaceConstraint(self::ORIGINAL_TYPES);
    }

    /** @param  list<string>  $types */
    private function replaceConstraint(array $types): void
    {
        $list = collect($types)->map(fn (string $t): string => "'".$t."'")->implode(',');

        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_type_check');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_check CHECK (product_type IN ({$list}))");
    }
};
