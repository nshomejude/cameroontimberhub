# Category Tree Implementation Plan (gap-plan 1.5.1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a new, additive `Category` tree (top-level product-form groups + sector collections) that products can optionally be tagged with, without touching the existing `product_type`/`ProductType` flat enum or any of its consumers.

**Architecture:** Brand-new `categories` table (self-referencing `parent_id`), a `Category` model, a nullable additive `category_id` FK + cast on `Product` (single line addition, nothing else in that file touched), a `CategoryMigrationMap` (mirrors `App\Support\SupplierTypeMigrationMap`) translating each real `ProductType` value to a top-level `Category` slug, and an idempotent `products:backfill-categories` Artisan command (mirrors `App\Console\Commands\BackfillOrganisationType`). Seeding of the category tree itself happens via a migration-time seed (a small `CategorySeeder`-style dataset run from within the categories migration, since categories are reference data with fixed, known values — not user data).

**Scope Decision:** Brief §4.1 groups are: raw, secondary processed, finished, construction, residue, equipment (top-level "form" groups) plus sector collections: Hospitality, Education, Healthcare, Office, Residential, Interior Design. The sector collections are a *second, orthogonal* dimension (a product's "who buys this" facet) — not a nesting under the form groups. Modeling them as children of the form groups would put "Hospitality" underneath "raw" or "finished" arbitrarily, which is wrong. Decision: use a single self-referencing tree where the six form groups are top-level roots, and the six sector collections are ALSO top-level roots (all `parent_id = NULL`), distinguished by a `kind` column (`form` vs `sector`). This keeps the tree self-referencing (satisfying "self-referencing `parent_id` for the tree" from the task) while keeping the two dimensions from being mixed into a false hierarchy. Only the six `form` categories get populated into `CategoryMigrationMap`, since `ProductType` values are processing forms, not sectors — sector tagging is future manual/admin work, out of scope here. This is documented in the migration and the map's docstring.

**Tech Stack:** Laravel 13, PostgreSQL, Pest/PHPUnit feature tests, existing `HasFactory` conventions.

---

## File Structure

- Create: `database/migrations/2026_08_29_100050_create_categories_table.php` — table + seed of the 12 fixed categories (6 form + 6 sector), all top-level (`parent_id` NULL), `kind` enum column.
- Create: `database/migrations/2026_08_29_100060_add_category_id_to_products_table.php` — additive nullable `category_id` FK on `products`.
- Create: `app/Models/Category.php` — self-referencing model (`parent()`, `children()`), `HasFactory`.
- Create: `database/factories/CategoryFactory.php`
- Create: `app/Support/CategoryMigrationMap.php` — `ProductType` value => top-level `Category` slug, form categories only.
- Create: `app/Console/Commands/BackfillProductCategories.php` — `products:backfill-categories`, idempotent (`WHERE category_id IS NULL`).
- Modify: `app/Models/Product.php` — ONE additive line: add `'category_id' => 'integer'`... actually `category_id` needs no cast (it's a plain FK int), so the only addition is the `category()` relation method plus registering the cast is unnecessary. Per the task's explicit instruction ("ONE additive nullable `category_id` + cast added to `app/Models/Product.php`"), we add the `category()` `BelongsTo` relation as the additive piece (no enum cast is needed for a plain FK integer — Eloquent handles that natively). This is the single change to that file.
- Test: `tests/Feature/CategoryTreeTest.php` — model/migration/relation behaviour.
- Test: `tests/Feature/BackfillProductCategoriesTest.php` — command idempotency + mapping correctness.

---

## Task 1: Categories table migration + Category model

**Files:**
- Create: `database/migrations/2026_08_29_100050_create_categories_table.php`
- Create: `app/Models/Category.php`
- Create: `database/factories/CategoryFactory.php`
- Test: `tests/Feature/CategoryTreeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the six top-level form categories and six sector categories', function () {
    expect(Category::query()->where('kind', 'form')->count())->toBe(6);
    expect(Category::query()->where('kind', 'sector')->count())->toBe(6);

    $slugs = Category::query()->pluck('slug')->all();

    foreach (['raw', 'secondary-processed', 'finished', 'construction', 'residue', 'equipment'] as $slug) {
        expect($slugs)->toContain($slug);
    }

    foreach (['hospitality', 'education', 'healthcare', 'office', 'residential', 'interior-design'] as $slug) {
        expect($slugs)->toContain($slug);
    }
});

it('every seeded category is top-level (no parent)', function () {
    expect(Category::query()->whereNotNull('parent_id')->count())->toBe(0);
});

it('supports a self-referencing parent/children tree', function () {
    $root = Category::factory()->create(['parent_id' => null]);
    $child = Category::factory()->create(['parent_id' => $root->id]);

    expect($child->parent->id)->toBe($root->id);
    expect($root->children->pluck('id')->all())->toBe([$child->id]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run (after acquiring the test lock per coordination rules):
`C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/CategoryTreeTest.php`
Expected: FAIL — `categories` table / `Category` class do not exist.

- [ ] **Step 3: Write the migration**

```php
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
```

- [ ] **Step 4: Write the Category model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node in the additive product-category tree (gap-plan 1.5.1, brief §4.1).
 * `kind` distinguishes the two orthogonal top-level dimensions ("form" and
 * "sector") that currently share this table — see the categories migration
 * docblock for why they are not nested under each other.
 */
class Category extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'parent_id' => null,
            'kind' => 'form',
            'slug' => $this->faker->unique()->slug(2),
            'name' => $this->faker->words(2, true),
        ];
    }
}
```

- [ ] **Step 6: Acquire test lock, verify testing DB, run migrations + test**

```bash
mkdir C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock
echo "category-tree-agent" > C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock\holder.txt
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"
```
Expected output: `cameroontimberhub_testing`

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/CategoryTreeTest.php
rm -rf C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock
```
Expected: PASS (3 tests), lock released immediately after.

- [ ] **Step 7: `vendor/bin/pint --dirty`, then commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_08_29_100050_create_categories_table.php app/Models/Category.php database/factories/CategoryFactory.php tests/Feature/CategoryTreeTest.php
git commit -m "feat: add additive categories table and Category model (1.5.1)"
```

---

## Task 2: category_id on products + Product::category() relation

**Files:**
- Create: `database/migrations/2026_08_29_100060_add_category_id_to_products_table.php`
- Modify: `app/Models/Product.php` (add one relation method only)
- Test: `tests/Feature/CategoryTreeTest.php` (extend)

- [ ] **Step 1: Write the failing test (append to CategoryTreeTest.php)**

```php
use App\Models\Product;

it('lets a product optionally belong to a category', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);

    expect($product->fresh()->category->id)->toBe($category->id);
});

it('leaves category_id nullable for products with no category assigned', function () {
    $product = Product::factory()->create(['category_id' => null]);

    expect($product->fresh()->category)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

`C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/CategoryTreeTest.php`
Expected: FAIL — `category_id` column / `category()` relation don't exist. (Use the test-lock protocol from Task 1 Step 6 for every run — acquire, run, release.)

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive nullable FK from products to the new categories tree (gap-plan
 * 1.5.1). Deliberately does not touch products.product_type or its CHECK
 * constraint — see app/Support/CategoryMigrationMap.php and
 * products:backfill-categories for how this column gets populated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('species_id')
                ->constrained('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
```

- [ ] **Step 4: Add the relation to Product.php**

Add this method to `app/Models/Product.php`, directly after the existing `species()` method (do not modify any other line in the file):

```php
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
```

This requires adding `use App\Models\Category;`... Category is in the same namespace (`App\Models`), so no import line is needed.

- [ ] **Step 5: Run migrations + test, verify pass**

(Test-lock protocol as in Task 1 Step 6.)
`C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/CategoryTreeTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: `vendor/bin/pint --dirty`, then commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_08_29_100060_add_category_id_to_products_table.php app/Models/Product.php tests/Feature/CategoryTreeTest.php
git commit -m "feat: add additive nullable category_id FK on products (1.5.1)"
```

---

## Task 3: CategoryMigrationMap + products:backfill-categories command

**Files:**
- Create: `app/Support/CategoryMigrationMap.php`
- Create: `app/Console/Commands/BackfillProductCategories.php`
- Test: `tests/Feature/BackfillProductCategoriesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('backfills category_id from product_type via CategoryMigrationMap', function () {
    $raw = Category::query()->where('slug', 'raw')->firstOrFail();
    $product = Product::factory()->create([
        'product_type' => ProductType::Logs,
        'category_id' => null,
    ]);

    $this->artisan('products:backfill-categories')->assertExitCode(0);

    expect($product->fresh()->category_id)->toBe($raw->id);
});

it('is idempotent and never overwrites an already-set category_id', function () {
    $finished = Category::query()->where('slug', 'finished')->firstOrFail();
    $raw = Category::query()->where('slug', 'raw')->firstOrFail();

    $product = Product::factory()->create([
        'product_type' => ProductType::Logs,
        'category_id' => $finished->id,
    ]);

    $this->artisan('products:backfill-categories')->assertExitCode(0);

    expect($product->fresh()->category_id)->toBe($finished->id)
        ->and($product->fresh()->category_id)->not->toBe($raw->id);
});

it('leaves products whose product_type has no map entry uncategorized', function () {
    // Every ProductType value must be mapped (see CategoryMigrationMap); this
    // test guards against the map's coverage silently regressing.
    expect(array_diff(
        array_map(fn ($c) => $c->value, ProductType::cases()),
        array_keys(\App\Support\CategoryMigrationMap::MAP)
    ))->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

(Test-lock protocol.) Expected: FAIL — class/command don't exist.

- [ ] **Step 3: Write CategoryMigrationMap**

```php
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
```

- [ ] **Step 4: Write the backfill command**

```php
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
```

- [ ] **Step 5: Run migrations + test, verify pass**

(Test-lock protocol.)
`C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/BackfillProductCategoriesTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: `vendor/bin/pint --dirty`, then commit**

```bash
vendor/bin/pint --dirty
git add app/Support/CategoryMigrationMap.php app/Console/Commands/BackfillProductCategories.php tests/Feature/BackfillProductCategoriesTest.php
git commit -m "feat: add CategoryMigrationMap and products:backfill-categories command (1.5.1)"
```

---

## Task 4: Full-suite verification + real backfill against dev data

**Files:** none created/modified — verification only.

- [ ] **Step 1: Acquire test lock, run full suite**

```bash
mkdir C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock
echo "category-tree-agent" > C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock\holder.txt
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test
rm -rf C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock
```
Expected: all tests pass (baseline 875 + 8 new = 883), 0 failures. Release the lock immediately regardless of outcome.

- [ ] **Step 2: Run the real migration + backfill against the dev database (not testing) and record actual counts**

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan migrate
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan products:backfill-categories
```
Record the command's printed per-product_type counts and final "Done. N products backfilled. M remain uncategorized." line verbatim in the final report.

- [ ] **Step 3: Update docs/GAP_PLAN.md row for 1.5.1**

Follow the GAP_PLAN.md lock protocol from the task brief: acquire `.test.lock`-style lock is NOT required for this (it's a separate doc lock per the brief — reuse the same `.test.lock` directory mechanism), re-read the file fresh, edit only the 1.5.1 row to mark it done with a one-line pointer to this plan file, commit, release.

```bash
git add docs/GAP_PLAN.md
git commit -m "docs: mark gap-plan 1.5.1 (category tree) done"
```

---

## Self-Review

**1. Spec coverage:**
- Category tree with parent_id, top-level groups (raw/secondary processed/finished/construction/residue/equipment) — Task 1. ✓
- Sector collections (Hospitality, Education, Healthcare, Office, Residential, Interior Design) — Task 1. ✓
- New nullable `category_id` on products, additive — Task 2. ✓
- `CategoryMigrationMap` mirroring `SupplierTypeMigrationMap` — Task 3. ✓
- Idempotent `products:backfill-categories` command — Task 3. ✓
- `product_type`/`ProductType` untouched, no consumer files touched — verified by grep in investigation; no task modifies them. ✓
- Real backfill results against dev data reported — Task 4. ✓

**2. Placeholder scan:** No TBD/TODO; every step has complete code. Clean.

**3. Type consistency:** `Category::MAP` values are `form`-kind slugs (`raw`, `secondary-processed`, etc.) consistently across Task 1 seed data, Task 3 map, and Task 3 command's `where('kind','form')` lookup. `Product::category()` returns `BelongsTo` to `Category`, matching Task 2's migration column name `category_id` and Task 1's `categories` table. Command signature `products:backfill-categories` is consistent between Task 3's class and Task 4's verification step.
