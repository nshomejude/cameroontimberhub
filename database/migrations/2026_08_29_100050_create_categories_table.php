<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive foundation for gap-plan 1.5.1 (brief §4.1). Self-referencing tree
 * of product categories. Two orthogonal dimensions share this one table,
 * distinguished by `kind`:
 *
 *  - `form`   — the six processing-form groups (raw, secondary processed,
 *               finished, construction, residue, equipment).
 *  - `sector` — the six sector collections used for merchandising
 *               (Hospitality, Education, Healthcare, Office, Residential,
 *               Interior Design).
 *
 * Both dimensions are top-level today (parent_id NULL) — sector collections
 * are NOT nested under form groups, since a product's sector and its form
 * are independent facets, not a hierarchy. `parent_id` exists so either
 * dimension can grow sub-categories later without another migration.
 *
 * This table is purely additive: it does not touch products.product_type or
 * App\Enums\ProductType. See app/Support/CategoryMigrationMap.php for how
 * ProductType values map onto the `form` categories.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('kind', 10);
            $table->string('slug', 60)->unique();
            $table->string('name', 100);
            $table->timestampsTz();

            $table->index(['kind', 'parent_id']);
        });

        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_kind_check CHECK (kind IN ('form','sector'))");

        $now = now();

        $form = [
            ['slug' => 'raw', 'name' => 'Raw'],
            ['slug' => 'secondary-processed', 'name' => 'Secondary Processed'],
            ['slug' => 'finished', 'name' => 'Finished'],
            ['slug' => 'construction', 'name' => 'Construction'],
            ['slug' => 'residue', 'name' => 'Residue'],
            ['slug' => 'equipment', 'name' => 'Equipment'],
        ];

        $sector = [
            ['slug' => 'hospitality', 'name' => 'Hospitality'],
            ['slug' => 'education', 'name' => 'Education'],
            ['slug' => 'healthcare', 'name' => 'Healthcare'],
            ['slug' => 'office', 'name' => 'Office'],
            ['slug' => 'residential', 'name' => 'Residential'],
            ['slug' => 'interior-design', 'name' => 'Interior Design'],
        ];

        DB::table('categories')->insert(
            collect($form)->map(fn (array $c) => $c + ['kind' => 'form', 'parent_id' => null, 'created_at' => $now, 'updated_at' => $now])
                ->concat(collect($sector)->map(fn (array $c) => $c + ['kind' => 'sector', 'parent_id' => null, 'created_at' => $now, 'updated_at' => $now]))
                ->all()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
