# Inventory Model Implementation Plan (1.5.6)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build gap-plan item 1.5.6 — a per-product/location `Inventory` model with an "Available now" quantity, decremented when an order is placed against it, per brief §4 (domestic marketplace "available now" filter).

**Architecture:** A new `inventory` table, one row per `(product_id, location)`, with `quantity_available`. A `InventoryService::reserve()` method atomically decrements on order creation (row-locked, refuses to go negative), and `restock()` for admin/manual replenishment. Wired into `OrderService::createFromQuote()` as an additive step — do not restructure that method, only add a call.

**Tech Stack:** Laravel 13, Pest.

Reference: `CTH_Claude_Code_Build_Brief.md` §4 ("Inventory per product/location with 'Available now', decremented on order"), `docs/GAP_PLAN.md` item 1.5.6, `app/Models/Product.php`, `app/Services/OrderService.php`, `app/Services/CertificateAllocationService.php` (the house pattern for an atomic row-locked ledger — mirror its `lockForUpdate()` + transaction shape).

**Module boundary (do not touch anything outside this list):** new `Inventory` model/migration/factory/service, ONE additive call inserted into `OrderService::createFromQuote()` (do not restructure that method otherwise), new tests. Do NOT touch: `Company.php`, `Capacity.php`, `Certificate*`, `ContactMessage*`, `Document*`, `ChainedActivity*`, `Consent*`, any Filament resource, `Species*`, `CompanyController.php`, `resources/views/components/layouts/app.blade.php`. Other agents are concurrently working on: a 0.1b redesign (docs-only), 0.2b implementation (Company.php + VerificationFlowService.php + new CompanyVerificationMirror.php), domestic Knowledge Centre content (editorial, docs/seeders only), and SEO/display defect fixes (Species*, CompanyController.php, app.blade.php).

## Scope decision

**What exists today, confirmed by investigation:** `grep -rli "inventory\b" app database/migrations` — confirm this returns nothing before starting (greenfield). `OrderService::createFromQuote()` exists and is the real order-creation entry point (confirmed during planning) — wire into it, don't invent a parallel order path.

**Location is a plain string, not a new model.** The brief says "per product/location" — a full location/warehouse model is out of scope for this primitive; `location` is a nullable string column (e.g. "Douala yard", "Main warehouse") a supplier fills in freely. If multi-location becomes a real requirement later, that's a follow-up, not blocking this item.

---

### Task 1: `Inventory` model, migration, factory

**Files:**
- Create: `database/migrations/2026_08_29_100070_create_inventory_table.php`
- Create: `app/Models/Inventory.php`
- Create: `database/factories/InventoryFactory.php`
- Test: `tests/Feature/InventoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Inventory;
use App\Models\Product;

it('records available quantity for a product at a location', function () {
    $product = Product::factory()->create();

    $inventory = Inventory::create([
        'product_id' => $product->id,
        'location' => 'Douala yard',
        'quantity_available' => 100,
        'unit' => 'm3',
    ]);

    expect($inventory->fresh())->not->toBeNull()
        ->and($inventory->product)->toBeInstanceOf(Product::class);
});

it('rejects a negative quantity_available via the CHECK constraint', function () {
    $product = Product::factory()->create();

    expect(fn () => \Illuminate\Support\Facades\DB::table('inventory')->insert([
        'product_id' => $product->id,
        'location' => 'Douala yard',
        'quantity_available' => -1,
        'unit' => 'm3',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/InventoryTest.php`
Expected: FAIL — `Class "App\Models\Inventory" not found`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product/location "available now" quantity (brief §4, gap-plan 1.5.6),
 * decremented atomically on order via InventoryService::reserve().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('location', 150)->nullable();
            $table->decimal('quantity_available', 12, 2)->default(0);
            $table->string('unit', 20);
            $table->timestampsTz();

            $table->index(['product_id', 'location']);
        });

        DB::statement('ALTER TABLE inventory ADD CONSTRAINT inventory_quantity_non_negative CHECK (quantity_available >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    use HasFactory;

    protected $table = 'inventory';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity_available' => 'decimal:2'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Inventory> */
class InventoryFactory extends Factory
{
    protected $model = Inventory::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'location' => $this->faker->randomElement(['Douala yard', 'Yaoundé warehouse', null]),
            'quantity_available' => $this->faker->randomFloat(2, 10, 500),
            'unit' => 'm3',
        ];
    }
}
```

- [ ] **Step 6: Run tests, migrate, Pint, commit**

```bash
php artisan migrate --force
php artisan test tests/Feature/InventoryTest.php
vendor/bin/pint --dirty
git add database/migrations/2026_08_29_100070_create_inventory_table.php app/Models/Inventory.php database/factories/InventoryFactory.php tests/Feature/InventoryTest.php
git commit -m "Add the Inventory model: per-product/location available quantity (brief §4, 1.5.6)"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** this worktree shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the foreground only, one run at a time. Before any `migrate:fresh`, verify `--env=testing` resolves to `cameroontimberhub_testing` via `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"`.

---

### Task 2: `InventoryService` — atomic reserve/restock

**Files:**
- Create: `app/Services/InventoryService.php`
- Test: `tests/Feature/InventoryServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Inventory;
use App\Services\InventoryService;

beforeEach(function () {
    $this->service = app(InventoryService::class);
});

it('reserves (decrements) available quantity atomically', function () {
    $inventory = Inventory::factory()->create(['quantity_available' => 100]);

    $this->service->reserve($inventory, 30);

    expect($inventory->fresh()->quantity_available)->toEqual(70);
});

it('refuses to reserve more than what is available', function () {
    $inventory = Inventory::factory()->create(['quantity_available' => 20]);

    expect(fn () => $this->service->reserve($inventory, 30))
        ->toThrow(RuntimeException::class, 'exceeds available');

    expect($inventory->fresh()->quantity_available)->toEqual(20);
});

it('restocks (increments) available quantity', function () {
    $inventory = Inventory::factory()->create(['quantity_available' => 50]);

    $this->service->restock($inventory, 25);

    expect($inventory->fresh()->quantity_available)->toEqual(75);
});
```

- [ ] **Step 2: Run to verify it fails, then write the service**

```php
<?php

namespace App\Services;

use App\Models\Inventory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Atomic reserve/restock against Inventory.quantity_available (brief §4,
 * gap-plan 1.5.6). Mirrors CertificateAllocationService's lockForUpdate()
 * pattern for the same "read remaining, then decrement" race condition.
 */
class InventoryService
{
    public function reserve(Inventory $inventory, float $quantity): Inventory
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Reserve quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($inventory, $quantity) {
            $locked = Inventory::query()->whereKey($inventory->getKey())->lockForUpdate()->firstOrFail();

            if ($quantity > $locked->quantity_available) {
                throw new RuntimeException("Reservation of {$quantity} {$locked->unit} exceeds available quantity of {$locked->quantity_available} {$locked->unit}.");
            }

            $locked->decrement('quantity_available', $quantity);

            return $locked->fresh();
        });
    }

    public function restock(Inventory $inventory, float $quantity): Inventory
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Restock quantity must be greater than zero.');
        }

        $inventory->increment('quantity_available', $quantity);

        return $inventory->fresh();
    }
}
```

- [ ] **Step 3: Run tests, full suite, Pint, commit**

```bash
php artisan test tests/Feature/InventoryServiceTest.php
php artisan test
vendor/bin/pint --dirty
git add app/Services/InventoryService.php tests/Feature/InventoryServiceTest.php
git commit -m "Add InventoryService: atomic reserve/restock against Inventory.quantity_available"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1.

---

### Task 3: Wire into `OrderService::createFromQuote()`

**Files:**
- Modify: `app/Services/OrderService.php` (additive call only)
- Test: extend the real existing order-creation test file (find via `find tests -iname "*Order*"` first)

- [ ] **Step 1: Read `OrderService::createFromQuote()` in full first.** Confirm its real signature and where an order's line items (product + quantity) become available within the method, so you insert the reserve call in the right place, on the right data — do not guess.

- [ ] **Step 2: Write a failing test** proving that creating an order from a quote reserves inventory for each line item with a matching `Inventory` row (by `product_id`), and does NOT fail/error for a line item whose product has no `Inventory` row at all (many products won't have one yet — this must be a graceful no-op, not a hard requirement, since inventory tracking is opt-in per product until suppliers start using it).

- [ ] **Step 3: Add the reserve call**, wrapped so a missing `Inventory` row is skipped silently (opt-in) and an insufficient-quantity failure is surfaced clearly (do not swallow that one) — read the real `createFromQuote()` method to decide whether an insufficient-inventory failure should abort order creation entirely or just log a warning; if genuinely ambiguous from the code, default to logging a warning and NOT blocking the order (inventory tracking is new and shouldn't be able to break the existing order flow on day one), and state this choice explicitly in your final report.

- [ ] **Step 4: Run tests, full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Services/OrderService.php tests/
git commit -m "Reserve inventory when an order is created from a quote, opt-in per product"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1.

---

### Task 4: Record progress

- [ ] Re-read `docs/GAP_PLAN.md` fresh under the `.test.lock` mechanism (contested file, other agents editing concurrently), mark `1.5.6` done, commit.

---

## Self-Review Notes

- **Module boundary respected:** only `Inventory`-related files and one additive call in `OrderService.php` touched.
- **Opt-in, not breaking:** a product with no `Inventory` row behaves exactly as today — this primitive doesn't retroactively require every product to have tracked inventory.
