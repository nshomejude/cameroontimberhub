# Organisation Type Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce the brief's 10-value `Organisation.type` taxonomy (`CTH_Claude_Code_Build_Brief.md` §10) alongside the existing 5-value `companies.supplier_type`, backfill only the mappings that are unambiguous, and leave `exporter`/`trader` unbackfilled pending an explicit human decision — without touching any of the 12 files that currently read `supplier_type`. This is item 0.6 of `docs/GAP_PLAN.md`.

**Architecture:** A new nullable `companies.type` column plus `App\Enums\OrganisationType` (10 cases), added additively next to the untouched `supplier_type` column and `App\Enums\SupplierType` enum. A `SupplierTypeMigrationMap` config-driven mapping class translates the 3 old values that have clean equivalents (`manufacturer`, `logistics_provider`, and — see Scope decision — `service_provider`'s fate is also deferred, not assumed) into the new column via a dedicated Artisan backfill command, runnable repeatedly and safely. `exporter` and `trader` rows are left with `type = NULL` and are surfaced by a report command so they're visible, not silently lost. No existing consumer of `supplier_type` is modified.

**Tech Stack:** Laravel 13, Filament 5, PostgreSQL, Pest.

Reference: `docs/GAP_PLAN.md` item 0.6, `docs/AUDIT.md` (data-model conflicts table, `companies.supplier_type` row), `CTH_Claude_Code_Build_Brief.md` §10, `docs/superpowers/plans/2026-08-27-polymorphic-document-store.md` (house style for scope decisions and additive migrations).

## Scope decision (read before executing)

### What exists today

`companies.supplier_type` is a nullable `string(32)` column with a Postgres CHECK constraint added in `database/migrations/2026_06_26_100030_add_supplier_metrics_to_companies_table.php`, backed by `App\Enums\SupplierType` (5 cases): `manufacturer`, `exporter`, `trader`, `service_provider`, `logistics_provider`. It is read or written in 12 files:

- `app/Models/Company.php` — cast to enum, `scopeOfSupplierType()`
- `app/Filament/Resources/Companies/Schemas/CompanyForm.php` — admin edit form Select
- `app/Filament/Resources/Companies/Tables/CompaniesTable.php` — admin table column + `SelectFilter`
- `app/Http/Requests/Api/V1/SupplierIndexRequest.php` — API `types[]` filter validation
- `app/Http/Resources/Api/V1/SupplierResource.php` — API response shape
- `app/Livewire/CompanyDirectory.php` — public directory facet counts and filter chips
- `app/Services/SearchService.php` — search filtering, delegates to `Company::scopeOfSupplierType()`
- `resources/views/components/supplier-row.blade.php` — public directory card
- `resources/views/public/companies/show.blade.php` — public supplier profile page
- `resources/views/public/products/show.blade.php` — product page supplier badge
- `database/seeders/DemoCompanySeeder.php` — 13 seeded companies, 5 with `Exporter`, 5 with `Manufacturer`, 2 with `Trader`, 1 with `LogisticsProvider`, 0 with `ServiceProvider`

This is a live, working facet system (public directory filter chips, API contract, admin form) — not a stub. Rule 0.3 in `CTH_Claude_Code_Build_Brief.md` requires confirming large refactors before doing them silently; replacing all 12 consumers' column and enum in the same pass as introducing the new 10-value taxonomy is exactly that kind of refactor, and it cannot be done safely yet because of the mapping gap below.

### The mapping gap: `exporter` and `trader`

The brief's target `Organisation.type` (§10) is: `supplier | processor | manufacturer | artisan | buyer | retailer | logistics | carbon_developer | financier | training_provider`. Three of the five current `SupplierType` values map cleanly:

| Old (`SupplierType`) | New (`OrganisationType`) | Confidence |
|---|---|---|
| `manufacturer` | `manufacturer` | Clean — identical concept |
| `logistics_provider` | `logistics` | Clean — identical concept, renamed |
| `service_provider` | *(none)* | **Also ambiguous** — see note below |
| `exporter` | *(none)* | **BLOCKED — needs human decision** |
| `trader` | *(none)* | **BLOCKED — needs human decision** |

`service_provider` has zero seeded demo companies and is not mentioned anywhere in `docs/GAP_PLAN.md` or `docs/AUDIT.md`'s conflict table, which only flags `exporter`/`trader` explicitly. It is included in the mapping table above for honesty, but this plan treats it the same way it treats `exporter`/`trader` — left unmapped, not guessed — because the brief gives no equivalent for "general service provider" either (`training_provider` is the closest match by icon/wrench semantics but is a specific brief-defined vertical, not a catch-all, and guessing would be exactly the kind of silent decision this plan exists to avoid).

**Why `exporter` and `trader` don't map**, based on reading `DemoCompanySeeder.php` and every consumer: in this codebase today, `supplier_type` answers "what commercial role does this company primarily play on the platform" — but `exporter` and `trader` describe a *trade behaviour* (they ship goods across a border, or they buy-and-resell without processing) layered on top of a company that is, underneath, a `supplier` in the brief's sense. The brief's 10-type list has exactly one supply-side "I sell raw or semi-processed timber" bucket (`supplier`) and does not carve out export or trading behaviour as a separate organisation type at all — it is arguably a company *attribute* (does this supplier handle export logistics/paperwork itself?), not a type.

**Three concrete options for the human decision**, ranked by how much they preserve today's directory facets versus how faithfully they follow the brief:

1. **Map both to `supplier`, add a separate boolean/enum attribute for the behaviour.** `exporter` → `type = supplier, handles_export = true`; `trader` → `type = supplier, is_reseller = true` (or a single `trade_role` enum with `producer|reseller` if the two need to be mutually exclusue-able later). Closest to the brief's literal 10-type model. Cost: the public directory's "Exporter" and "Trader" filter chips (`CompanyDirectory.php`, `supplier-row.blade.php`) either disappear or get rebuilt on the new attribute — that rebuild is out of scope for this plan (see "What happens to `supplier_type` consumers" below) and would need its own follow-up task.
2. **Map both to `supplier`, drop the distinction entirely** (no new attribute). Simplest, fully brief-compliant on the schema, but silently discards information the 13 demo companies and any real seeded data currently carry (5 of 13 demo companies are `Exporter`, 2 are `Trader` — 7 of 13, over half, would lose their differentiation). Not recommended without confirming no real (non-demo) data depends on the distinction — this plan does not have access to production data to check that.
3. **Treat `Organisation.type` as not the right place to encode this at all.** Keep `exporter`/`trader` as `type = NULL` in the new column permanently (not just "blocked for now"), and separately track export/trading capability as a company-level flag or a `role` join table if/when multi-role support lands (see `docs/GAP_PLAN.md` item 0.7, RBAC rebuild, which already anticipates "a sawmill both supplies and buys processing" — the same kind of multi-role reality). This defers the decision to 0.7 instead of resolving it here, on the theory that "this company also exports" is closer to a capability/role than a taxonomic type.

This plan does **not** pick one of these three. Task 3 below builds the mapping as a single associative array in one file (`app/Support/SupplierTypeMigrationMap.php`) specifically so that whichever option is chosen, applying it is a one-file edit plus a backfill command re-run — not a multi-file change.

### Additive, not replacement — and why

Given the above, a hard replacement (rename `supplier_type` → `type`, migrate the CHECK constraint, update all 12 consumers) cannot be done responsibly in this pass: 2 of 5 values have no confirmed target, and forcing a guess now to unblock the schema would produce exactly the kind of undiscussed data-loss this plan is supposed to prevent (see Option 2 above). Mirroring the polymorphic Document store plan (`docs/superpowers/plans/2026-08-27-polymorphic-document-store.md`, itself scoped down from a full merge to an additive build for the same reason), this plan:

- Adds `companies.type` as a **nullable** column, coexisting with `companies.supplier_type` — nothing is dropped or renamed.
- Adds `App\Enums\OrganisationType` as a **new, separate** enum — `App\Enums\SupplierType` is untouched.
- Backfills only `manufacturer` and `logistics_provider` (the 2 of 5 values with clean 1:1 equivalents) via an idempotent Artisan command.
- Leaves `exporter`, `trader`, and `service_provider` rows with `type = NULL`, and ships a `companies:organisation-type-gaps` report command that lists exactly which companies are affected, so the gap is visible and countable rather than silently dropped.
- Does **not** modify `Company.php`'s existing `supplier_type` cast/scope, any Filament resource, any API request/resource, `CompanyDirectory.php`, `SearchService.php`, or any Blade view. All 12 existing consumers keep reading `supplier_type` exactly as they do today.

### What happens to `supplier_type` consumers

None of the 12 files listed above are touched in this plan. Cutting every consumer over to `companies.type` requires the `exporter`/`trader` decision to be made first (Task 3's map to be completed for all 5 old values, not just 2), plus rebuilding the public directory's filter-chip UX for whichever new value set results — real, separate work. This mirrors how `docs/GAP_PLAN.md` split `0.1b` (migrating `company_documents`/`order_documents` onto the new polymorphic store) out from `0.1` (building the store) once 0.1's investigation found 24+ consumers needing a dedicated pass. The same split applies here: this plan is "0.6", and cutting the 12 consumers over — call it "0.6b" — is out of scope, tracked as a new gap-plan item in Task 5 below.

---

### Task 1: `OrganisationType` enum

**Files:**
- Create: `app/Enums/OrganisationType.php`
- Test: `tests/Unit/OrganisationTypeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\OrganisationType;

it('has exactly the 10 organisation types from the build brief', function () {
    $values = array_column(OrganisationType::cases(), 'value');

    expect($values)->toEqualCanonicalizing([
        'supplier',
        'processor',
        'manufacturer',
        'artisan',
        'buyer',
        'retailer',
        'logistics',
        'carbon_developer',
        'financier',
        'training_provider',
    ]);
});

it('gives every case a label and a color', function () {
    foreach (OrganisationType::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty()
            ->and($case->color())->toBeString()->not->toBeEmpty();
    }
});

it('lists options keyed by value', function () {
    expect(OrganisationType::options())->toHaveKey('processor', 'Processor');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/OrganisationTypeTest.php`
Expected: FAIL — `Class "App\Enums\OrganisationType" not found`.

- [ ] **Step 3: Write the enum**

```php
<?php

namespace App\Enums;

/**
 * The brief's target Organisation.type taxonomy (CTH_Claude_Code_Build_Brief.md
 * §10) — 10 cases, superseding the 5-value App\Enums\SupplierType long-term.
 *
 * Deliberately does NOT replace SupplierType yet. See the "Scope decision"
 * section of docs/superpowers/plans/2026-08-27-organisation-type-migration.md:
 * two of SupplierType's five values (exporter, trader) have no clean
 * equivalent here and are BLOCKED pending a human decision. SupplierType and
 * companies.supplier_type stay in place and in use until that decision is
 * made and the 12 consuming files are migrated in a dedicated follow-up
 * (tracked in docs/GAP_PLAN.md as the successor to item 0.6).
 */
enum OrganisationType: string
{
    case Supplier = 'supplier';
    case Processor = 'processor';
    case Manufacturer = 'manufacturer';
    case Artisan = 'artisan';
    case Buyer = 'buyer';
    case Retailer = 'retailer';
    case Logistics = 'logistics';
    case CarbonDeveloper = 'carbon_developer';
    case Financier = 'financier';
    case TrainingProvider = 'training_provider';

    public function label(): string
    {
        return match ($this) {
            self::Supplier => 'Supplier',
            self::Processor => 'Processor',
            self::Manufacturer => 'Manufacturer',
            self::Artisan => 'Artisan',
            self::Buyer => 'Buyer',
            self::Retailer => 'Retailer',
            self::Logistics => 'Logistics',
            self::CarbonDeveloper => 'Carbon Project Developer',
            self::Financier => 'Financier',
            self::TrainingProvider => 'Training Provider',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Supplier => 'success',
            self::Processor => 'info',
            self::Manufacturer => 'success',
            self::Artisan => 'warning',
            self::Buyer => 'primary',
            self::Retailer => 'warning',
            self::Logistics => 'danger',
            self::CarbonDeveloper => 'success',
            self::Financier => 'info',
            self::TrainingProvider => 'gray',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/OrganisationTypeTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Enums/OrganisationType.php tests/Unit/OrganisationTypeTest.php
git commit -m "Add the OrganisationType enum (brief section 10's 10-type taxonomy)"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** the suite shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the foreground only, one run at a time — never in the background, never concurrently. If you see "relation X already exists" or "relation migrations does not exist", that's corruption from a concurrent run: repair with `php artisan migrate:fresh --env=testing --force` after confirming `--env=testing` genuinely targets the testing database (a `.env.testing` file must exist — verify with `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"` first). **Never run `migrate:fresh` without that verification** — earlier in this project's history, running it without `.env.testing` in place silently wiped the dev database instead.

---

### Task 2: `companies.type` column, additive migration

**Files:**
- Create: `database/migrations/2026_08_29_100010_add_type_to_companies_table.php`
- Test: `tests/Feature/OrganisationTypeMigrationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\OrganisationType;
use App\Models\Company;
use Illuminate\Support\Facades\Schema;

it('has a nullable type column on companies alongside the untouched supplier_type column', function () {
    expect(Schema::hasColumn('companies', 'type'))->toBeTrue()
        ->and(Schema::hasColumn('companies', 'supplier_type'))->toBeTrue();
});

it('allows a null type', function () {
    $company = Company::factory()->create(['type' => null]);

    expect($company->fresh()->type)->toBeNull();
});

it('rejects a type value outside the 10-case OrganisationType list', function () {
    expect(fn () => \Illuminate\Support\Facades\DB::table('companies')->insert([
        ...Company::factory()->raw(),
        'type' => 'not_a_real_type',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('accepts every OrganisationType value', function () {
    foreach (OrganisationType::cases() as $case) {
        $company = Company::factory()->create(['type' => $case->value]);
        expect($company->fresh()->type)->toBe($case->value);
    }
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/OrganisationTypeMigrationTest.php`
Expected: FAIL — `Schema::hasColumn('companies', 'type')` is false (migration doesn't exist yet).

- [ ] **Step 3: Write the migration**

Verify against the actual schema before finalizing: confirm no column named `type` already exists on `companies` (`grep -rn "companies.*'type'" database/migrations/` — expect no hits other than this new file once written), and confirm the existing `supplier_type` CHECK constraint name (`companies_supplier_type_check`, added in `database/migrations/2026_06_26_100030_add_supplier_metrics_to_companies_table.php`) so this migration's new constraint uses a distinct name.

```php
<?php

use App\Enums\OrganisationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the brief's 10-value Organisation.type (CTH_Claude_Code_Build_Brief.md
 * §10) as `companies.type`, ADDITIVELY, alongside the existing 5-value
 * `companies.supplier_type` column (see
 * 2026_06_26_100030_add_supplier_metrics_to_companies_table.php).
 *
 * Deliberately does NOT drop, rename, or backfill supplier_type here. Two of
 * its five values (exporter, trader) have no clean OrganisationType
 * equivalent — see the "Scope decision" section of
 * docs/superpowers/plans/2026-08-27-organisation-type-migration.md for the
 * three mapping options awaiting a human decision. Only `manufacturer` and
 * `logistics_provider` are backfilled automatically, by the
 * companies:backfill-organisation-type command in Task 3 of that plan — this
 * migration only adds the column and its constraint; it does not populate
 * any row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('type', 32)->nullable()->after('supplier_type');
            $table->index('type');
        });

        $list = collect(OrganisationType::values())->map(fn (string $v): string => "'".$v."'")->implode(',');

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_organisation_type_check CHECK (type IS NULL OR type IN ({$list}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_organisation_type_check');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
```

- [ ] **Step 4: Add `type` to `Company`'s fillable and casts**

Modify `app/Models/Company.php`. Find the existing `$fillable` array (around line 49, includes `'supplier_type'`) and the `casts()` method (around line 67, casts `'supplier_type' => SupplierType::class`).

```php
// In $fillable, add 'type' next to 'supplier_type':
'status', 'logo_path', 'supplier_type', 'type', 'is_featured', 'verified_at',

// In casts(), add alongside the existing supplier_type cast:
'type' => \App\Enums\OrganisationType::class,
```

Do not add a scope, do not touch `scopeOfSupplierType()`, and do not import `OrganisationType` with a `use` statement at the top unless the fully-qualified form above is replaced with one — either is fine, but keep the change to exactly these two array entries. No other line in `Company.php` changes.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/OrganisationTypeMigrationTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Full suite, migrate, Pint, commit**

```bash
php artisan migrate --force
php artisan test
vendor/bin/pint --dirty
git add database/migrations/2026_08_29_100010_add_type_to_companies_table.php app/Models/Company.php tests/Feature/OrganisationTypeMigrationTest.php
git commit -m "Add companies.type (OrganisationType) additively alongside supplier_type"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1 — foreground only, one run at a time, verify `.env.testing` before any `migrate:fresh`.

---

### Task 3: Mapping table and idempotent backfill command

**Files:**
- Create: `app/Support/SupplierTypeMigrationMap.php`
- Create: `app/Console/Commands/BackfillOrganisationType.php`
- Test: `tests/Feature/BackfillOrganisationTypeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\OrganisationType;
use App\Enums\SupplierType;
use App\Models\Company;
use App\Support\SupplierTypeMigrationMap;

it('maps only the two unambiguous SupplierType values', function () {
    expect(SupplierTypeMigrationMap::MAP)->toBe([
        'manufacturer' => 'manufacturer',
        'logistics_provider' => 'logistics',
    ]);
});

it('leaves exporter, trader and service_provider unmapped', function () {
    foreach (['exporter', 'trader', 'service_provider'] as $blocked) {
        expect(SupplierTypeMigrationMap::MAP)->not->toHaveKey($blocked);
    }
});

it('backfills companies with a mappable supplier_type and leaves type null for the rest', function () {
    $manufacturer = Company::factory()->create(['supplier_type' => SupplierType::Manufacturer->value, 'type' => null]);
    $logistics = Company::factory()->create(['supplier_type' => SupplierType::LogisticsProvider->value, 'type' => null]);
    $exporter = Company::factory()->create(['supplier_type' => SupplierType::Exporter->value, 'type' => null]);
    $trader = Company::factory()->create(['supplier_type' => SupplierType::Trader->value, 'type' => null]);
    $untyped = Company::factory()->create(['supplier_type' => null, 'type' => null]);

    $this->artisan('companies:backfill-organisation-type')
        ->assertSuccessful();

    expect($manufacturer->fresh()->type)->toBe(OrganisationType::Manufacturer)
        ->and($logistics->fresh()->type)->toBe(OrganisationType::Logistics)
        ->and($exporter->fresh()->type)->toBeNull()
        ->and($trader->fresh()->type)->toBeNull()
        ->and($untyped->fresh()->type)->toBeNull();
});

it('is idempotent: running it twice does not change already-backfilled rows or error', function () {
    $company = Company::factory()->create(['supplier_type' => SupplierType::Manufacturer->value, 'type' => null]);

    $this->artisan('companies:backfill-organisation-type')->assertSuccessful();
    $firstRunType = $company->fresh()->type;

    $this->artisan('companies:backfill-organisation-type')->assertSuccessful();

    expect($company->fresh()->type)->toBe($firstRunType);
});

it('never overwrites a type that was already set manually', function () {
    $company = Company::factory()->create([
        'supplier_type' => SupplierType::Manufacturer->value,
        'type' => OrganisationType::Supplier->value,
    ]);

    $this->artisan('companies:backfill-organisation-type')->assertSuccessful();

    expect($company->fresh()->type)->toBe(OrganisationType::Supplier);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/BackfillOrganisationTypeTest.php`
Expected: FAIL — `Class "App\Support\SupplierTypeMigrationMap" not found`.

- [ ] **Step 3: Write the mapping class**

```php
<?php

namespace App\Support;

/**
 * The single source of truth for translating old App\Enums\SupplierType
 * values to the new App\Enums\OrganisationType values, during the additive
 * migration described in
 * docs/superpowers/plans/2026-08-27-organisation-type-migration.md.
 *
 * Deliberately covers only the two SupplierType values with an unambiguous
 * OrganisationType equivalent. `exporter`, `trader`, and `service_provider`
 * are intentionally absent — seeing them here would look like a completed
 * decision, when the "Scope decision" section of that plan documents three
 * unresolved options for exporter/trader specifically and flags
 * service_provider as equally unresolved.
 *
 * When the human decision is made (see the plan's "Scope decision" section),
 * extend this array — that is the ONLY file that needs a code change to
 * pick up the new mapping; App\Console\Commands\BackfillOrganisationType
 * already iterates this map generically. Re-run
 * `php artisan companies:backfill-organisation-type` afterwards; it is
 * idempotent and never overwrites a `type` that is already set (see its
 * WHERE clause), so re-running after extending this map only fills in rows
 * that are still NULL.
 */
final class SupplierTypeMigrationMap
{
    /**
     * @var array<string, string> old SupplierType value => new OrganisationType value
     */
    public const MAP = [
        'manufacturer' => 'manufacturer',
        'logistics_provider' => 'logistics',
    ];
}
```

- [ ] **Step 4: Write the backfill command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\SupplierTypeMigrationMap;
use Illuminate\Console\Command;

/**
 * Backfills companies.type from companies.supplier_type for the mappings in
 * SupplierTypeMigrationMap::MAP only. Idempotent and safe to re-run: it only
 * ever writes to rows where `type IS NULL`, so it never overwrites a value
 * set by hand (e.g. via the admin form once the OrganisationType Select
 * ships) or by a previous run.
 *
 * Rows whose supplier_type has no entry in the map (today: exporter, trader,
 * service_provider, and any NULL supplier_type) are left with type = NULL
 * and are not reported as errors — see companies:organisation-type-gaps for
 * a report of exactly which rows those are.
 */
class BackfillOrganisationType extends Command
{
    protected $signature = 'companies:backfill-organisation-type';

    protected $description = 'Backfill companies.type from companies.supplier_type for values with an unambiguous OrganisationType equivalent.';

    public function handle(): int
    {
        $total = 0;

        foreach (SupplierTypeMigrationMap::MAP as $oldValue => $newValue) {
            $updated = Company::query()
                ->where('supplier_type', $oldValue)
                ->whereNull('type')
                ->update(['type' => $newValue]);

            $this->info("Backfilled {$updated} compan".($updated === 1 ? 'y' : 'ies')." with supplier_type={$oldValue} to type={$newValue}.");

            $total += $updated;
        }

        $this->info("Done. {$total} companies backfilled.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/BackfillOrganisationTypeTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Support/SupplierTypeMigrationMap.php app/Console/Commands/BackfillOrganisationType.php tests/Feature/BackfillOrganisationTypeTest.php
git commit -m "Add the idempotent companies:backfill-organisation-type command"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1 — foreground only, one run at a time, verify `.env.testing` before any `migrate:fresh`.

---

### Task 4: Gap report command

**Files:**
- Create: `app/Console/Commands/ReportOrganisationTypeGaps.php`
- Test: `tests/Feature/ReportOrganisationTypeGapsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\SupplierType;
use App\Models\Company;

it('lists companies whose supplier_type has no OrganisationType mapping', function () {
    $exporter = Company::factory()->create(['name' => 'Exporter Co', 'supplier_type' => SupplierType::Exporter->value, 'type' => null]);
    $trader = Company::factory()->create(['name' => 'Trader Co', 'supplier_type' => SupplierType::Trader->value, 'type' => null]);
    Company::factory()->create(['name' => 'Manufacturer Co', 'supplier_type' => SupplierType::Manufacturer->value, 'type' => 'manufacturer']);

    $this->artisan('companies:organisation-type-gaps')
        ->assertSuccessful()
        ->expectsOutputToContain('Exporter Co')
        ->expectsOutputToContain('Trader Co')
        ->doesntExpectOutputToContain('Manufacturer Co');
});

it('reports zero gaps cleanly when everything mappable is backfilled', function () {
    Company::factory()->create(['supplier_type' => SupplierType::Manufacturer->value, 'type' => 'manufacturer']);

    $this->artisan('companies:organisation-type-gaps')
        ->assertSuccessful()
        ->expectsOutputToContain('0 companies');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/ReportOrganisationTypeGapsTest.php`
Expected: FAIL — command `companies:organisation-type-gaps` does not exist.

- [ ] **Step 3: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\SupplierTypeMigrationMap;
use Illuminate\Console\Command;

/**
 * Makes the exporter/trader (and any other unmapped) gap visible and
 * countable instead of a silent NULL. Run this after
 * companies:backfill-organisation-type to see exactly which companies still
 * need a type — i.e. every row whose supplier_type is set but has no entry
 * in SupplierTypeMigrationMap::MAP, or whose supplier_type is set but type
 * is still NULL for any other reason.
 */
class ReportOrganisationTypeGaps extends Command
{
    protected $signature = 'companies:organisation-type-gaps';

    protected $description = 'List companies with a supplier_type but no backfilled companies.type (exporter, trader, and any other unmapped value).';

    public function handle(): int
    {
        $gaps = Company::query()
            ->whereNotNull('supplier_type')
            ->whereNull('type')
            ->get(['id', 'name', 'supplier_type']);

        if ($gaps->isEmpty()) {
            $this->info('0 companies with an unmapped supplier_type.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'supplier_type', 'Mapped in SupplierTypeMigrationMap?'],
            $gaps->map(fn (Company $company) => [
                $company->id,
                $company->name,
                $company->supplier_type?->value,
                array_key_exists($company->supplier_type?->value, SupplierTypeMigrationMap::MAP) ? 'yes' : 'no',
            ]),
        );

        $this->warn("{$gaps->count()} companies have a supplier_type with no companies.type mapping yet.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/ReportOrganisationTypeGapsTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Console/Commands/ReportOrganisationTypeGaps.php tests/Feature/ReportOrganisationTypeGapsTest.php
git commit -m "Add companies:organisation-type-gaps to surface unmapped exporter/trader rows"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1 — foreground only, one run at a time, verify `.env.testing` before any `migrate:fresh`.

---

### Task 5: Run the backfill against real data, verify the gap report, record the follow-up item

**Files:**
- Modify: `docs/GAP_PLAN.md` (append a follow-up row; see Step 3)

- [ ] **Step 1: Run the backfill against the dev database**

```bash
php artisan companies:backfill-organisation-type
```

Expected output: two `Backfilled N compan(y|ies)...` lines (one per `manufacturer`, one per `logistics_provider`) followed by `Done. N companies backfilled.` — `N` will match whatever real/demo data exists; there is no fixed expected count.

- [ ] **Step 2: Run the gap report and read its output**

```bash
php artisan companies:organisation-type-gaps
```

Expected: a table listing every company whose `supplier_type` is `exporter`, `trader`, or `service_provider` (or any other value absent from `SupplierTypeMigrationMap::MAP`), each row showing "no" in the "Mapped?" column. This is the concrete list a human uses to make the exporter/trader decision from the "Scope decision" section — it shows exactly how many real rows are affected, not just the 7 demo rows counted during planning.

- [ ] **Step 3: Append the follow-up item to `docs/GAP_PLAN.md`**

Read the existing Phase 0 table in `docs/GAP_PLAN.md` (item 0.6 is the row this plan implements). Add a new row directly after it:

```markdown
| 0.6b | **Resolve `exporter`/`trader` → `Organisation.type` mapping and cut the 12 `supplier_type` consumers over.** Pick one of the three options in `docs/superpowers/plans/2026-08-27-organisation-type-migration.md`'s "Scope decision" section, extend `SupplierTypeMigrationMap::MAP` accordingly, re-run `companies:backfill-organisation-type`, then migrate `Company.php`, both Filament resources, the API request/resource pair, `CompanyDirectory.php`, `SearchService.php`, and the 3 Blade views off `supplier_type` onto `type`. Only then drop `supplier_type` and `SupplierType`. | Blocks nothing else directly, but `supplier_type`/`SupplierType` staying in place indefinitely means every §4 directory feature keeps building on the 5-value model instead of the brief's 10-value one. | 3 |
```

Use the exact insertion point: immediately below the existing `| 0.6 | ... | 4 |` row, above `| 0.7 | ...`.

- [ ] **Step 4: Commit**

```bash
git add docs/GAP_PLAN.md
git commit -m "Record 0.6b: the exporter/trader decision and consumer cutover, as follow-up to 0.6"
```

No test run needed for this task — it is a documentation-only change plus two already-tested command invocations.

---

## Self-review

**Spec coverage:**
- Exact enum case list and mapping table (old → new, BLOCKED where applicable): Task 1 (`OrganisationType`, 10 cases) and Task 3 (`SupplierTypeMigrationMap::MAP`, 2 clean mappings; exporter/trader/service_provider explicitly absent and explained in the Scope decision). Covered.
- Additive vs replacement decision, justified: "Additive, not replacement — and why" in Scope decision. Covered.
- What happens to existing `supplier_type` consumers: "What happens to `supplier_type` consumers" in Scope decision — explicitly untouched, cutover tracked as 0.6b in Task 5. Covered.
- Exporter/trader mapping options presented for human decision, not silently picked: "The mapping gap" subsection, 3 ranked options with tradeoffs. Covered.
- Standard test-environment safety notes in every task that runs tests: present at the end of Tasks 1–4 (Task 5 runs no test suite, so it is exempt by design).

**Placeholder scan:** no TBD/TODO, no "add appropriate error handling", no "similar to Task N" — every step has complete, runnable code including the migration, enum, mapping class, both commands, and every test file in full.

**Type consistency:** `OrganisationType` cases and `SupplierTypeMigrationMap::MAP` values match across Tasks 1, 2, 3, 4 (`manufacturer`, `logistics`). `Company::$casts['type']` cast to `OrganisationType::class` in Task 2 matches the enum used in Task 3's and Task 4's tests (`OrganisationType::Manufacturer`, `->type)->toBeNull()`, etc.). Command signatures (`companies:backfill-organisation-type`, `companies:organisation-type-gaps`) match between their class definitions and every test/step that invokes them.
