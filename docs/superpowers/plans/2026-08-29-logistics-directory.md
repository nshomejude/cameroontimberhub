# Logistics Directory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship gap-plan item 1.5.9 — a read-only public directory of `OrganisationType::Logistics` companies at `/logistics-directory`, with "trusted" / "tech-enabled" verification tiers shown per company, mirroring the Transformation Network directory (gap-plan 1.5.3).

**Architecture:** One new controller (`LogisticsDirectoryController@index`), one new route, one new Blade view. Read-only queries against the existing `Company` model (already `HasVerification`, already carries `OrganisationType::Logistics`) — no writes, no schema changes, no new models.

**Tech Stack:** Laravel 13, Pest, Blade, existing `Verification`/`VerificationStage` framework (gap-plan 0.2).

---

## Scope Decision — "trusted" / "tech-enabled" tiers

Investigated: `Company` already has `HasVerification` (morphOne `Verification`, `isVerified()` = stage in `[Verified, Published]`). There is **no** existing "tier" or "tech-enabled" concept anywhere in `Verification`, `VerificationStage`, `BadgeType`, or the `companies` table.

Decision (additive, **no schema change**):
- **Trusted tier** = `Company::isVerified()` is true (existing method, existing signal — a Logistics company whose Verification has reached `verified` or `published`).
- **Tech-enabled tier** = Trusted **and** `website_url` is not null (existing nullable column on `companies`, already used elsewhere as a real-world digital-presence signal — not invented data). This is a real, already-collected fact about the company, not a new subsystem. If a company has no verification at all, or is not yet trusted, it is shown as "Unverified" and never labeled tech-enabled.

This is computed on the fly in the controller/view (a `tier(): string` accessor added to nothing — computed inline) — no new column, no new model, no touch to `Company.php`, `BadgeType.php`, or the Verification framework itself. If a real "tech tracking" signal (GPS, live tracking, fleet API) is ever modeled (gap-plan 1.5.11/1.5.12, owned by other concurrent agents), this tier can be upgraded later without a breaking change since it's computed, not stored.

## File Structure

- Create: `app/Http/Controllers/Public/LogisticsDirectoryController.php` — `index()` action, filtered/paginated Logistics companies, computes tier per company for the view.
- Create: `resources/views/public/logistics-directory/index.blade.php` — directory grid, mirrors `transformation-network/index.blade.php`.
- Modify: `routes/web.php` — add `GET /logistics-directory` route, named `logistics-directory`.
- Create: `tests/Feature/LogisticsDirectoryTest.php` — Pest feature tests.

---

### Task 1: Controller + route

**Files:**
- Create: `app/Http/Controllers/Public/LogisticsDirectoryController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/LogisticsDirectoryTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Enums\VerificationStage;
use App\Models\Company;

it('lists only logistics companies', function () {
    $logistics = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Logistics]);
    $supplier = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Supplier]);

    $response = $this->get('/logistics-directory');

    $response->assertOk();
    $response->assertSee($logistics->name);
    $response->assertDontSee($supplier->name);
});

it('filters the directory by region', function () {
    $centre = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Logistics, 'region' => 'Centre']);
    $littoral = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Logistics, 'region' => 'Littoral']);

    $response = $this->get('/logistics-directory?region=Centre');

    $response->assertSee($centre->name);
    $response->assertDontSee($littoral->name);
});

it('labels a verified logistics company as Trusted', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Logistics]);
    $company->verification()->create(['stage' => VerificationStage::Verified]);

    $response = $this->get('/logistics-directory');

    $response->assertOk();
    $response->assertSeeInOrder([$company->name, 'Trusted']);
});

it('labels a verified logistics company with a website as Tech-enabled', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Logistics, 'website_url' => 'https://example.com']);
    $company->verification()->create(['stage' => VerificationStage::Published]);

    $response = $this->get('/logistics-directory');

    $response->assertOk();
    $response->assertSeeInOrder([$company->name, 'Tech-enabled']);
});

it('labels a logistics company with no verification as Unverified', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Draft, 'type' => OrganisationType::Logistics]);

    $response = $this->get('/logistics-directory');

    $response->assertOk();
    $response->assertSeeInOrder([$company->name, 'Unverified']);
});

it('does not label a company with an in-progress verification as Trusted', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Logistics]);
    $company->verification()->create(['stage' => VerificationStage::CompanyInfo]);

    $response = $this->get('/logistics-directory');

    $response->assertOk();
    $response->assertSeeInOrder([$company->name, 'Unverified']);
    $response->assertDontSee('Trusted');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run (from the worktree root, after acquiring the `.test.lock` — see coordination note below):
`C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=LogisticsDirectoryTest`
Expected: FAIL (route/controller/view don't exist yet — 404s).

- [ ] **Step 3: Create the controller**

```php
<?php

namespace App\Http\Controllers\Public;

use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Gap-plan 1.5.9 — a directory of logistics/transport companies
 * (App\Enums\OrganisationType::Logistics), with "trusted" / "tech-enabled"
 * verification tiers layered over the shared Verification framework
 * (gap-plan 0.2). Structurally mirrors TransformationNetworkController
 * (gap-plan 1.5.3): a Company directory filtered by OrganisationType,
 * read-only, no writes, no schema changes.
 *
 * Tiers are computed, not stored (see the plan's Scope Decision):
 *  - "Trusted"      = Company::isVerified() (Verification stage verified/published)
 *  - "Tech-enabled"  = Trusted AND the company has a website_url on file
 *  - anything else  = "Unverified"
 */
class LogisticsDirectoryController extends Controller
{
    public const TIER_TECH_ENABLED = 'Tech-enabled';

    public const TIER_TRUSTED = 'Trusted';

    public const TIER_UNVERIFIED = 'Unverified';

    public function index(Request $request): View
    {
        $region = (string) $request->query('region', '');

        $companies = $this->base()
            ->when($region !== '', fn (Builder $q) => $q->where('region', $region))
            ->orderBy('legal_name')
            ->paginate(12)
            ->withQueryString();

        return view('public.logistics-directory.index', [
            'companies' => $companies,
            'region' => $region,
            'tierOf' => fn (Company $company): string => $this->tierOf($company),
        ]);
    }

    /** Logistics companies -- the base set for the directory. */
    private function base(): Builder
    {
        return Company::query()
            ->where('type', OrganisationType::Logistics->value)
            ->where('status', CompanyStatus::Verified->value);
    }

    private function tierOf(Company $company): string
    {
        if (! $company->isVerified()) {
            return self::TIER_UNVERIFIED;
        }

        return $company->website_url !== null
            ? self::TIER_TECH_ENABLED
            : self::TIER_TRUSTED;
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, add the `use` statement near the other `Public` controller imports (alongside `TransformationNetworkController`):

```php
use App\Http\Controllers\Public\LogisticsDirectoryController;
```

And add the route near the `transformation-network` routes:

```php
Route::get('/logistics-directory', [LogisticsDirectoryController::class, 'index'])->name('logistics-directory');
```

- [ ] **Step 5: Create the view**

```blade
<x-layouts.app
    title="Logistics Directory — verified Cameroon transport & logistics companies"
    description="Find logistics and transport companies in Cameroon. Trusted and Tech-enabled verification tiers, filterable by region.">

    <div class="max-w-7xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold mb-2">Logistics Directory</h1>
        <p class="text-gray-600 mb-6">Find transport and logistics companies in Cameroon's timber supply chain.</p>

        <form method="get" class="flex flex-wrap gap-3 mb-8">
            <input type="text" name="region" value="{{ $region }}" placeholder="Region" class="border rounded px-3 py-2">
            <button type="submit" class="px-4 py-2 rounded bg-primary-600 text-white">Filter</button>
        </form>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @forelse ($companies as $company)
                @php($tier = $tierOf($company))
                <a href="{{ route('companies.show', $company->slug) }}" class="block border rounded p-4 hover:shadow">
                    <div class="font-semibold">{{ $company->name }}</div>
                    <div class="text-sm text-gray-500">{{ $company->type?->label() }} &middot; {{ $company->region }}</div>
                    <span @class([
                        'inline-block mt-2 text-xs font-medium px-2 py-1 rounded',
                        'bg-green-100 text-green-800' => $tier === \App\Http\Controllers\Public\LogisticsDirectoryController::TIER_TECH_ENABLED,
                        'bg-blue-100 text-blue-800' => $tier === \App\Http\Controllers\Public\LogisticsDirectoryController::TIER_TRUSTED,
                        'bg-gray-100 text-gray-600' => $tier === \App\Http\Controllers\Public\LogisticsDirectoryController::TIER_UNVERIFIED,
                    ])>{{ $tier }}</span>
                </a>
            @empty
                <p class="text-gray-500">No logistics companies match these filters yet.</p>
            @endforelse
        </div>

        <div class="mt-6">{{ $companies->links() }}</div>
    </div>

</x-layouts.app>
```

- [ ] **Step 6: Run tests to verify they pass**

Same lock-guarded command as Step 2. Expected: all `LogisticsDirectoryTest` tests PASS.

- [ ] **Step 7: Run `vendor/bin/pint --dirty`, then commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Public/LogisticsDirectoryController.php resources/views/public/logistics-directory/index.blade.php routes/web.php tests/Feature/LogisticsDirectoryTest.php
git commit -m "feat: add Logistics Directory with Trusted/Tech-enabled verification tiers (gap-plan 1.5.9)"
```

---

### Task 2: Full-suite verification + GAP_PLAN.md update

- [ ] **Step 1:** Acquire the shared test-run lock, run the full suite once, release the lock (see coordination note).
- [ ] **Step 2:** Acquire the GAP_PLAN.md lock, re-read the file fresh, mark item 1.5.9 done with a one-line note and the commit SHA, commit, release the lock.

---

## Coordination notes (shared worktree, 5 concurrent agents)

**Test-run lock:** before any `php artisan test` invocation, atomically `mkdir .test.lock`; on success write `holder.txt`, run exactly one test command, then remove `.test.lock`. On failure (already locked), wait ~10s and retry, up to ~150 attempts. Treat a `holder.txt` older than 15 minutes as stale and remove it.

**GAP_PLAN.md lock:** same mechanism, scoped to `docs/GAP_PLAN.md` edits — acquire, re-read fresh, edit only the 1.5.9 row, commit, release.

**Company.php is not touched by this plan** — no coordination needed there.

---

## Self-review

**Spec coverage:** directory of Logistics companies (Task 1 controller+route+view) — covered. Trusted/tech-enabled tiers over the Phase 0 Verification framework — covered by the Scope Decision + `tierOf()`. Read-only, no writes — confirmed, controller only queries. Mirrors Transformation Network pattern — confirmed (same controller/route/view shape, same `base()` idiom). Tests — 6 Pest tests covering filtering, all three tiers, and the boundary case of an in-progress (non-terminal) verification stage not being counted as Trusted.

**Placeholder scan:** none — every step has complete code.

**Type consistency:** `tierOf(Company $company): string` returns one of the three `LogisticsDirectoryController::TIER_*` constants consistently used in both the controller closure passed to the view and the view's `@class` conditional.
