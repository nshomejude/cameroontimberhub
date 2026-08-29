# Capacity Model Implementation Plan (1.5.4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build gap-plan item 1.5.4 — the first Phase 1.5 (domestic marketplace) primitive: a `Capacity` model `{capability, quantity, unit, period}` letting a company publish structured production/service capacity ("Kiln drying 100 m³/month", "Furniture 500 units/month"), plus a search that can answer "who can produce 5,000 chairs/month?" (brief §4.3).

**Architecture:** A new `capacities` table, polymorphic `owner_type`/`owner_id` (only `Company` populates it today, but polymorphic from day one — this mirrors every other primitive built this session: `Document`, `Verification`, `Consent` — so a future `Processor`/`Artisan`-specific model from 1.5.3/1.5.8 doesn't need a migration to participate). A `CapacityService` for create/update, and a `scopeMatching()` query the brief's own "who can produce X per period" search maps onto directly.

**Tech Stack:** Laravel 13, Filament 5, Pest.

Reference: `CTH_Claude_Code_Build_Brief.md` §4.3 ("Capacity discovery"), `docs/GAP_PLAN.md` item 1.5.4, `app/Models/Concerns/HasDocuments.php`/`HasConsents.php` (the house polymorphic-trait pattern this plan follows), `app/Models/Company.php`.

**Module boundary (do not touch anything outside this list):** new `Capacity` model/migration/factory/trait/service, one new addition to `Company.php` (the `HasCapacities` trait only — a single `use` clause addition, nothing else in that file), new tests. Do NOT touch `Document*`, `Consent*`, `Certificate*`, `ContactMessage*`, `CompanyDocument*`, `ChainedActivity*`, or any Filament resource other than optionally a new dedicated `Capacities` relation manager (own new file) — do not modify `CompaniesTable.php`/`CompanyForm.php`, which other concurrently-dispatched agents' modules may also touch.

## Scope decision

**What exists today, confirmed by investigation:** `grep -rli "capacity\b" app database/migrations` — confirm this returns nothing before starting (greenfield, matching the brief's own "not yet built" framing for §4). This plan builds the primitive only — company capacity records and the matching search. It does NOT build 1.5.3 (Transformation Network directory UI), 1.5.2 (domestic search experience), or any Filament admin screen beyond a minimal relation manager for staff to see/edit a company's capacity rows — those are separate, larger Phase 1.5 items with their own scope.

---

### Task 1: `Capacity` model, migration, factory

**Files:**
- Create: `database/migrations/2026_08_29_100060_create_capacities_table.php`
- Create: `app/Models/Capacity.php`
- Create: `database/factories/CapacityFactory.php`
- Test: `tests/Feature/CapacityTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Capacity;
use App\Models\Company;

it('records a structured capacity entry for a company', function () {
    $company = Company::factory()->create();

    $capacity = Capacity::create([
        'owner_type' => Company::class,
        'owner_id' => $company->id,
        'capability' => 'Kiln drying',
        'quantity' => 100,
        'unit' => 'm3',
        'period' => 'month',
    ]);

    expect($capacity->fresh())->not->toBeNull()
        ->and($capacity->owner)->toBeInstanceOf(Company::class)
        ->and($capacity->owner->is($company))->toBeTrue();
});

it('rejects a period value outside the allowed set via the CHECK constraint', function () {
    $company = Company::factory()->create();

    expect(fn () => \Illuminate\Support\Facades\DB::table('capacities')->insert([
        'owner_type' => Company::class,
        'owner_id' => $company->id,
        'capability' => 'Kiln drying',
        'quantity' => 100,
        'unit' => 'm3',
        'period' => 'not_a_real_period',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('rejects a non-positive quantity via the CHECK constraint', function () {
    $company = Company::factory()->create();

    expect(fn () => \Illuminate\Support\Facades\DB::table('capacities')->insert([
        'owner_type' => Company::class,
        'owner_id' => $company->id,
        'capability' => 'Kiln drying',
        'quantity' => 0,
        'unit' => 'm3',
        'period' => 'month',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/CapacityTest.php`
Expected: FAIL — `Class "App\Models\Capacity" not found`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Structured production/service capacity (brief §4.3, gap-plan 1.5.4):
 * {capability, quantity, unit, period}, e.g. "Kiln drying 100 m3/month".
 * Polymorphic owner from day one -- only Company populates it today, but
 * this mirrors every other primitive built this session (Document,
 * Verification, Consent) so a future Processor/Artisan-specific model
 * doesn't need a migration to participate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capacities', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('owner_type', 120);
            $table->unsignedBigInteger('owner_id');
            $table->string('capability', 150);
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 30);
            $table->string('period', 20);
            $table->timestampsTz();

            $table->index(['owner_type', 'owner_id']);
            $table->index('capability');
        });

        DB::statement("ALTER TABLE capacities ADD CONSTRAINT capacities_period_check CHECK (period IN ('day','week','month','quarter','year'))");
        DB::statement('ALTER TABLE capacities ADD CONSTRAINT capacities_quantity_positive CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('capacities');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Capacity extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Brief §4.3's "who can produce 5,000 chairs/month?" search: matches a
     * capability by partial text and a quantity/period pair that can
     * actually satisfy the request (this owner's capacity, normalized to
     * the same period, must be >= the requested quantity).
     */
    public function scopeMatching(Builder $query, string $capability, float $minQuantity, string $period): Builder
    {
        return $query
            ->where('capability', 'ilike', "%{$capability}%")
            ->where('period', $period)
            ->where('quantity', '>=', $minQuantity);
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Capacity;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Capacity> */
class CapacityFactory extends Factory
{
    protected $model = Capacity::class;

    public function definition(): array
    {
        return [
            'owner_type' => Company::class,
            'owner_id' => Company::factory(),
            'capability' => $this->faker->randomElement(['Kiln drying', 'Sawing', 'Planing', 'Furniture assembly', 'CNC machining']),
            'quantity' => $this->faker->randomFloat(2, 10, 1000),
            'unit' => $this->faker->randomElement(['m3', 'units', 'jobs']),
            'period' => $this->faker->randomElement(['month', 'week', 'quarter']),
        ];
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan migrate --force && php artisan test tests/Feature/CapacityTest.php`
Expected: PASS (3 tests).

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** this worktree shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the foreground only, one run at a time — never in the background, never concurrently. Before any `migrate:fresh`, verify `--env=testing` genuinely resolves to `cameroontimberhub_testing` via `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"` — never skip this.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_08_29_100060_create_capacities_table.php app/Models/Capacity.php database/factories/CapacityFactory.php tests/Feature/CapacityTest.php
git commit -m "Add the Capacity model: structured {capability, quantity, unit, period} entries (brief §4.3)"
```

---

### Task 2: `HasCapacities` trait, wired to `Company`

**Files:**
- Create: `app/Models/Concerns/HasCapacities.php`
- Modify: `app/Models/Company.php` (one `use` clause addition only)
- Test: `tests/Feature/CompanyCapacityTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Capacity;
use App\Models\Company;

it('gives Company a capacities relation via HasCapacities', function () {
    $company = Company::factory()->create();
    Capacity::factory()->for($company, 'owner')->create(['capability' => 'Kiln drying', 'quantity' => 100, 'period' => 'month']);

    expect($company->capacities()->count())->toBe(1)
        ->and($company->capacities()->first()->capability)->toBe('Kiln drying');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/CompanyCapacityTest.php`
Expected: FAIL — `Call to undefined method App\Models\Company::capacities()`.

- [ ] **Step 3: Write the trait**

```php
<?php

namespace App\Models\Concerns;

use App\Models\Capacity;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasCapacities
{
    public function capacities(): MorphMany
    {
        return $this->morphMany(Capacity::class, 'owner');
    }
}
```

- [ ] **Step 4: Wire it onto `Company`**

Read `app/Models/Company.php`'s current `use` trait clause first (per the collision-risk pattern that recurred with `Species`/`Product` in items 0.1/0.2 — confirm no stopgap `capacities()` method already exists before adding the trait). Add `HasCapacities` to the trait list and the corresponding `use App\Models\Concerns\HasCapacities;` import. Do not touch any other line in this file — other concurrently-dispatched agents may also be editing `Company.php`; if you see uncommitted changes there that aren't yours when you go to edit, re-read the file fresh and add your one line alongside theirs, don't overwrite.

- [ ] **Step 5: Run tests, full suite, Pint, commit**

```bash
php artisan test tests/Feature/CompanyCapacityTest.php
php artisan test
vendor/bin/pint --dirty
git add app/Models/Concerns/HasCapacities.php app/Models/Company.php tests/Feature/CompanyCapacityTest.php
git commit -m "Add HasCapacities trait, wired to Company as the first real consumer"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1.

---

### Task 3: The "who can produce X per period" search

**Files:**
- Test: `tests/Feature/CapacitySearchTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Capacity;
use App\Models\Company;

it('finds companies whose capacity satisfies a capability/quantity/period request', function () {
    $sufficient = Company::factory()->create(['legal_name' => 'Big Kiln Co']);
    Capacity::factory()->for($sufficient, 'owner')->create(['capability' => 'Kiln drying', 'quantity' => 200, 'period' => 'month']);

    $insufficient = Company::factory()->create(['legal_name' => 'Small Kiln Co']);
    Capacity::factory()->for($insufficient, 'owner')->create(['capability' => 'Kiln drying', 'quantity' => 50, 'period' => 'month']);

    $wrongPeriod = Company::factory()->create(['legal_name' => 'Weekly Kiln Co']);
    Capacity::factory()->for($wrongPeriod, 'owner')->create(['capability' => 'Kiln drying', 'quantity' => 500, 'period' => 'week']);

    $matches = Capacity::query()->matching('Kiln', 100, 'month')->with('owner')->get();

    expect($matches)->toHaveCount(1)
        ->and($matches->first()->owner->legal_name)->toBe('Big Kiln Co');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/CapacitySearchTest.php`
Expected: FAIL if `scopeMatching()` (Task 1) has a bug, otherwise this should already PASS since Task 1 built it — this task exists specifically to prove the search works end-to-end with real multi-company data, not just unit-test the scope in isolation.

- [ ] **Step 3: If it fails, fix `Capacity::scopeMatching()`; if it already passes, this task is verification-only**

- [ ] **Step 4: Commit if any fix was needed**

```bash
php artisan test tests/Feature/CapacitySearchTest.php
git add app/Models/Capacity.php tests/Feature/CapacitySearchTest.php
git commit -m "Prove the capacity-matching search end-to-end with real multi-company data"
```

If no fix was needed, still commit the new test file alone (`git add tests/Feature/CapacitySearchTest.php`) so this end-to-end proof is preserved.

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1.

---

### Task 4: Record progress

**Files:** `docs/GAP_PLAN.md`

- [ ] Re-read the file fresh (contested — other agents may be editing concurrently). Mark `1.5.4` done in Phase 1.5's table with a one-line summary and commit SHAs; note explicitly that 1.5.2/1.5.3 (the actual domestic search UI and Transformation Network directory) are NOT covered by this item and remain open.
- [ ] Commit: `git commit -m "Mark 1.5.4 (Capacity model) done"`.

---

## Self-Review Notes

- **Module boundary respected:** only `Capacity`-related files and one `Company.php` trait-wiring line touched. No other agent's module referenced.
- **Polymorphic from day one:** matches the house pattern (`Document`, `Verification`, `Consent`) rather than hard-coding `Company` into the schema, so 1.5.3's future Processor/Artisan models can participate without a migration.
- **No scope creep:** explicitly does not build the domestic search UI (1.5.2) or the Transformation Network directory (1.5.3) — just the data primitive and its own search scope, proven end-to-end.
