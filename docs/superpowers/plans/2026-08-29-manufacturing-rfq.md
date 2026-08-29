# Gap-plan 1.5.5 — Manufacturing RFQ / Local Procurement Hub / Project RFQ

## Scope Decision

**Investigation findings (read in full before writing this plan):**

1. **`rfqs.type` does not exist.** Grepped every `database/migrations/*rfq*` file
   and read `app/Models/Rfq.php`, `app/Enums/RfqStatus.php`, and every
   `app/Enums/Rfq*.php` file in full. There is no `type` column on `rfqs`, no
   `RfqType` enum, and no migration that ever added one. The gap-plan's premise
   that 1.5 already built "the `rfqs.type` work" is false — that prerequisite
   was never done. This item's real first task is adding it from scratch.

2. **Multi-line-item RFQs already exist and already work.** `rfq_items` is a
   `hasMany` off `rfqs` (see `2026_06_22_120020_create_rfq_items_table.php` and
   `Rfq::items()`), and the public wizard (`app/Http/Controllers/Public/RfqController.php`
   + `app/Services/RfqWizard.php`) already has a "Products & Requirements" step
   with add/remove-row repeater controls, `RfqWizard::MAX_ITEMS = 10`, and full
   server-side validation of an `items[]` array — this is exactly "500 doors +
   200 windows for one project" in the requested/goal sense: N heterogeneous
   line items, each with its own species/text label, quantity, and unit, on one
   RFQ header. **No new data model, no new line-item table, and no
   `IntakeService::createRfq()` signature change are needed** — `createRfq()`
   already accepts `array $header, array $items` and merges `$header` straight
   into `Rfq::create()`, so a `type` value just needs to ride along inside
   `$header`.

3. **`RfqItem` already tolerates non-timber-species rows.** `species_id` is
   nullable, `species_text` is a free string, `form`/`grade`/`dimensions`/
   `moisture_content` are all nullable, and `unit` already includes `pcs`
   (`RfqUnit::Piece`). A domestic manufacturing line like "500 doors" fits as
   `species_text = 'Doors'`, `quantity = 500`, `unit = 'pcs'` without touching
   `RfqItem`, `Product`, or any catalog file — consistent with this module's
   boundary (`Product.php`/`Category*` are off limits, owned by 1.5.1).

4. **`RfqWizard::rules('delivery')` already makes everything but
   `destination_country_code` optional** (`shipping_port` nullable, `incoterm`
   nullable). A domestic buyer submitting a manufacturing/project RFQ just
   supplies their own country code there (e.g. `CM`) — no wizard validation
   rule needs to change for the domestic path to work end-to-end today.

**Conclusion / architecture decision:** "Manufacturing RFQ" and "Local
Procurement Hub" and "Project RFQ" are **the same underlying mechanism** — a
multi-line RFQ submitted through the existing wizard — distinguished only by a
new `type` tag on the `rfqs` row, set via a second entry point
(`/request-quote/manufacturing`) that seeds the wizard session with
`type = domestic_manufacturing` before the buyer starts. There is no
justification for two distinct entry points with different mechanics; a single
new type value covers "Local Procurement Hub" and "Project RFQ" both, since
neither the brief nor the existing schema gives a reason to split them further
inside this module's boundary. If a future item wants to give domestic RFQs a
different step sequence, copy, or field set, that's an additive change to
`RfqWizard`/`RfqController`, not a new module.

**Additive-only plan:**
- New `RfqType` enum (`Export`, `DomesticManufacturing`), following the
  `RfqStatus` pattern (backed string enum, `label()`).
- New migration: nullable-turned-`NOT NULL DEFAULT 'export'` `type` column on
  `rfqs` with a CHECK constraint, mirroring the house style already used for
  `status`/`visibility` in `2026_06_22_120010_create_rfqs_table.php`. Existing
  rows all backfill to `'export'` via the column default — zero behavior
  change for the existing export wizard.
- `Rfq` model: cast `type` to `RfqType`, add `scopeOfType()`.
- `RfqWizard`: two small additive methods, `type()`/`putType()`, backed by the
  same session bag, defaulting to `RfqType::Export` when unset.
- `RfqController`: new `createManufacturing()` action bound to
  `GET /request-quote/manufacturing`, which seeds the wizard exactly like
  `create()` but also stamps `type = domestic_manufacturing` into the session,
  then renders the same `details` step of the same wizard view. `store()`
  reads `$wizard->type()` and folds it into the header array passed to
  `IntakeService::createRfq()` — an additive one-line change, no signature
  change.
- No change to `IntakeService::createRfq()`'s signature or behavior for the
  export path — `type` simply arrives as another key in `$header`, exactly
  like `title`/`project_name` do today.
- No change to `RfqTriageService` — out of this item's scope per the module
  boundary; routing/triage for domestic RFQs is a separate future concern.

## Tasks (TDD, one commit each)

### Task 1 — `RfqType` enum
- Test: `tests/Unit` not used elsewhere for enums in this codebase; cover via
  the feature test in Task 3 instead (an enum with no behavior beyond
  `label()` doesn't need its own unit test file here — matches house pattern,
  `RfqStatus` has none).
- Add `app/Enums/RfqType.php`:
```php
<?php

namespace App\Enums;

enum RfqType: string
{
    case Export = 'export';
    case DomesticManufacturing = 'domestic_manufacturing';

    public function label(): string
    {
        return match ($this) {
            self::Export => 'Export',
            self::DomesticManufacturing => 'Manufacturing / Local Procurement',
        };
    }
}
```

### Task 2 — migration + model cast
- Migration `database/migrations/2026_08_29_140010_add_type_to_rfqs_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->string('type', 30)->default('export')->after('visibility');
        });

        DB::statement("ALTER TABLE rfqs ADD CONSTRAINT rfqs_type_check CHECK (type IN ('export','domestic_manufacturing'))");
    }

    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            DB::statement('ALTER TABLE rfqs DROP CONSTRAINT IF EXISTS rfqs_type_check');
            $table->dropColumn('type');
        });
    }
};
```
- `Rfq::casts()`: add `'type' => RfqType::class,`.
- `Rfq`: add
```php
public function scopeOfType(Builder $query, RfqType $type): Builder
{
    return $query->where('type', $type->value);
}
```
- Test `tests/Feature/RfqTypeTest.php`: factory-create an `Rfq` with no `type`
  given, assert it defaults to `RfqType::Export`; create one with
  `type: RfqType::DomesticManufacturing`, assert cast round-trips and
  `Rfq::query()->ofType(RfqType::DomesticManufacturing)` finds it and excludes
  the export one.

### Task 3 — wizard type + manufacturing entry point + IntakeService wiring
- `RfqWizard`: add
```php
public function type(): RfqType
{
    return RfqType::tryFrom((string) Session::get(self::KEY.'.type')) ?? RfqType::Export;
}

public function putType(RfqType $type): void
{
    Session::put(self::KEY.'.type', $type->value);
}
```
  (adjust to whatever nesting `all()`/`Session::get(self::KEY, [])` actually
  uses — store it as a top-level key inside the same session array via
  `$state = $this->all(); $state['type'] = $type->value; Session::put(self::KEY, $state);`
  and read it back the same way, matching `putStep()`'s existing pattern
  exactly rather than a dotted session key.)
- `RfqWizard::clear()`: unchanged — clearing the whole `self::KEY` bag already
  clears `type` too.
- `RfqController`:
  - New method `createManufacturing(Request $request, RfqWizard $wizard, RfqList $list): View|RedirectResponse`
    — same body as `create()`, plus `$wizard->putType(RfqType::DomesticManufacturing);`
    before `seed()` runs (or right after — must land before `render()`).
  - `store()`: when building the header array passed to
    `$intake->createRfq(...)`, add `'type' => $wizard->type()->value,` to the
    first `array_merge(...)` argument.
- `routes/web.php`: add
  `Route::get('/request-quote/manufacturing', [RfqController::class, 'createManufacturing'])->name('rfq.create.manufacturing');`
  next to the existing `rfq.create` route.
- Test `tests/Feature/ManufacturingRfqTest.php`:
  - GET `/request-quote/manufacturing` returns 200 and renders the wizard
    (same `details` step view as the export flow).
  - Full wizard walk (details → products with 2 line items "Doors"/"Windows",
    qty 500/200, unit pcs → delivery with a CM destination → contact →
    review → submit) via the existing step-by-step POST flow (mirror the
    pattern already used in `tests/Feature/RfqWizardTest.php` for the export
    path) results in an `Rfq` row with `type === RfqType::DomesticManufacturing`
    and exactly 2 `rfq_items` rows.
  - A plain `/request-quote` (export) submission still produces
    `type === RfqType::Export` (regression check — confirms the additive
    default didn't change existing behavior).

## Self-review checklist (fill in during implementation)
- [ ] No existing test broken; full suite count matches or exceeds the
      875-baseline plus new tests.
- [ ] `IntakeService::createRfq()` signature untouched.
- [ ] `Product.php`, `Company.php`, `Category*`, `Certificate*`,
      `ContactMessage*`, `Document*`, `ChainedActivity*`, `Consent*`,
      `Inventory*`, `BadgeType.php`/`BadgeService.php`, `RfqTriageService.php`
      untouched.
- [ ] `vendor/bin/pint --dirty` run before each commit.
- [ ] Migration is additive/backward compatible (default + backfill, no
      dropped/renamed columns).
- [ ] GAP_PLAN.md 1.5.5 row updated under the shared lock protocol.
