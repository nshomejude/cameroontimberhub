# Fleet & driver registry (gap-plan 1.5.12)

## Goal

"Fleet & driver registry with document expiries feeding alerts."

## Scope decision: reuse the existing polymorphic Document store

The codebase already has:
- `Document` (`app/Models/Document.php`) — polymorphic `owner` store with
  `expires_at`, verification workflow, hash chain.
- `HasDocuments` trait (`app/Models/Concerns/HasDocuments.php`) — one-line
  `documents()` MorphMany, already consumed by `Species`.
- `SendDocumentExpiryReminderJob` — already walks every `Document` row with
  `expires_at` set (regardless of owner type) and logs a threshold-bucketed
  reminder to `DocumentReminderLog` (polymorphic `document_owner_type` /
  `document_owner_id`).

Decision: `Vehicle` and `Driver` get `HasDocuments` and nothing else for
expiry alerting. No new document table, no new expiry job, no new reminder
log. Attaching a `Document` row with `owner_type = App\Models\Vehicle` (or
`Driver`) and an `expires_at` makes it visible to the existing job the next
time it runs — verified empirically in Task 5 below via tinker, not just by
reading the job's code.

House pattern copied: `Species` (`app/Models/Species.php`) — `use
HasDocuments, HasFactory, ...`, `protected $guarded = ['id']`, plain
`casts()`.

## Module boundary (do not touch)

New files only: `Vehicle`, `Driver` models, their migrations/factories, one
additive relation pair on `Company.php` (read fresh immediately before
editing), a new controller/route/view. Do NOT modify `Document.php`,
`HasDocuments.php`, `SendDocumentExpiryReminderJob.php`,
`DocumentReminderLog.php`, or any file outside this list.

Table names: `vehicles`, `drivers` (obvious names — 1.5.10 Transport RFQ may
FK to them later).

## Tasks

### Task 1 — `vehicles` table + `Vehicle` model + factory

Migration `database/migrations/2026_08_29_150010_create_vehicles_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('registration_number', 40);
            $table->string('type', 40); // truck, pickup, trailer, van, ...
            $table->decimal('capacity_tonnes', 8, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'registration_number']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
```

`app/Models/Vehicle.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasDocuments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company-owned vehicle in the fleet registry (item 1.5.12). Compliance
 * paperwork (registration, insurance, roadworthiness) is NOT a bespoke
 * column set here -- it rides the shared polymorphic Document store via
 * HasDocuments (see app/Models/Document.php), so expiry alerts come for
 * free from the existing SendDocumentExpiryReminderJob.
 */
class Vehicle extends Model
{
    use HasDocuments, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'capacity_tonnes' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
```

`database/factories/VehicleFactory.php` — `company_id` via
`Company::factory()`, `registration_number` unique-ish faker (e.g.
`CM-{random 4 digits}-{2 letters}`), `type` from a fixed list, `is_active`
true.

TDD: `tests/Feature/VehicleTest.php` — factory creates a valid row;
`company()->id` matches; `documents()` returns a `HasDocuments`-provided
MorphMany (attach a `Document::factory()->for($vehicle, 'owner')` and assert
`$vehicle->documents` contains it).

### Task 2 — `drivers` table + `Driver` model + factory

Same shape. Migration `2026_08_29_150020_create_drivers_table.php`:
`company_id` FK, `name` (150), `license_number` (60), `phone` (40)
nullable, `is_active` boolean default true, unique
`(company_id, license_number)`.

`app/Models/Driver.php` mirrors `Vehicle.php` (HasDocuments, HasFactory,
`company()` BelongsTo). `database/factories/DriverFactory.php`.

TDD: `tests/Feature/DriverTest.php`, mirroring VehicleTest.

### Task 3 — `Company::vehicles()` / `Company::drivers()`

Read `app/Models/Company.php` fresh immediately before editing. Add, next
to the existing `documents()`/`products()` HasMany block:

```php
public function vehicles(): HasMany
{
    return $this->hasMany(Vehicle::class);
}

public function drivers(): HasMany
{
    return $this->hasMany(Driver::class);
}
```

No other line touched. TDD: extend `tests/Feature/VehicleTest.php` /
`DriverTest.php` (or a small `CompanyFleetRelationsTest.php`) asserting
`$company->vehicles` / `$company->drivers` return the created rows.

### Task 4 — Fleet registry list view (controller + route + view)

Plain web controller (not a Filament resource — keeps this out of the
shared panel providers other agents are touching), scoped to the signed-in
user's own company, mirroring how `Company::scopeDashboardOwned()` scopes
the exporter dashboard.

`app/Http/Controllers/FleetRegistryController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Company-facing fleet & driver registry (gap-plan 1.5.12). Lists the
 * signed-in user's company vehicles/drivers with document expiry status,
 * reusing the shared Document store (see Vehicle/Driver::documents()).
 */
class FleetRegistryController extends Controller
{
    public function index(Request $request): View
    {
        $company = $request->user()->companies()->first();

        abort_if($company === null, 404);

        $vehicles = $company->vehicles()->with('documents')->orderBy('registration_number')->get();
        $drivers = $company->drivers()->with('documents')->orderBy('name')->get();

        return view('fleet.index', compact('company', 'vehicles', 'drivers'));
    }
}
```

Route in `routes/web.php`, `auth` middleware only (not `buyer`, since a
company member — not a buyer — is the audience):

```php
Route::middleware(['auth'])->get('/fleet', [FleetRegistryController::class, 'index'])->name('fleet.index');
```

View `resources/views/fleet/index.blade.php` — simple table: vehicle
registration/type/capacity, driver name/license, and a per-row "documents"
column showing each attached Document's type/expiry with a visual flag
(expired / expiring soon / ok) computed from `Document::isExpired()` and
`expires_at`.

TDD: `tests/Feature/FleetRegistryTest.php` —
- guest is redirected to login;
- user with no company gets 404;
- user with a company sees their own vehicles/drivers and NOT another
  company's;
- a vehicle document nearing expiry renders an "expiring" indicator (assert
  on response content).

### Task 5 — Empirical expiry-alert walkthrough (no code change, verification only)

Via `php artisan tinker` against the testing DB (inside the test-run lock):
1. Create a Company, a Vehicle for it, a Document owned by that vehicle with
   `expires_at` = today + 25 days (inside the 30-day threshold bucket).
2. Run `(new SendDocumentExpiryReminderJob)->handle()`.
3. Assert a `DocumentReminderLog` row exists with
   `document_owner_type = App\Models\Vehicle::class` (note: the Document's
   *owner*, `Vehicle`, is not what's logged — re-read the job: it logs
   `Document::class` itself as `document_owner_type`, keyed by the
   Document row's own id, polymorphic over Document rows not over
   Vehicle/Driver rows). Confirm this by reading job output for real, not
   assuming.
4. Capture the exact tinker transcript in the final report.

## Self-review checklist (before final commit)

- [ ] No file outside the allowed list touched (`git diff --stat` against
      the module boundary list).
- [ ] `Company.php` diff is exactly two added methods, nothing else
      changed.
- [ ] `vendor/bin/pint --dirty` run before each commit.
- [ ] Full suite green under the test-run lock, count reported.
- [ ] Tinker walkthrough transcript is real output, not narrated.
