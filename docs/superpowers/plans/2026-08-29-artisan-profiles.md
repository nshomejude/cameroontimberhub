# Artisan / Professional Profiles with Portfolio Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give Artisan/professional companies a portfolio of finished work (title, description, materials/species used, completion date, image) attached to their existing company profile, with a dedicated public page to browse it.

**Architecture:** `CompanyGallery` (table `company_gallery`) is already a per-image, sort-ordered, plan-capped collection scoped to a `Company` with `caption`/`alt_text`. A portfolio item is the same shape (one image + write-up) plus three extra facts: a longer description, a completion date, and free-text materials/species used. Rather than build a parallel `Portfolio`/`PortfolioItem` model, extend `company_gallery` additively with three nullable columns (`description`, `materials_used`, `completed_on`) and a boolean `is_portfolio` flag so the same table serves both plain marketing photos and portfolio pieces without forcing every gallery row to carry portfolio metadata. A new `CompanyController::portfolio()` action + `public.companies.portfolio` route/view renders only the portfolio-flagged rows for a company, gated to companies whose `type` is `OrganisationType::Artisan` (404 otherwise, matching the existing `publiclyVisible()` 404-not-disclose pattern).

**Tech Stack:** Laravel 13, Eloquent, Pest, Blade, existing `company_gallery` table/model, `App\Enums\OrganisationType`.

---

## Scope Decision

Investigated `app/Models/CompanyGallery.php` (table `company_gallery`: `id`, `company_id`, `image_path`, `caption`, `alt_text`, `sort_order`, timestamps — plan-capped via `Company::maxGalleryImages()` in `booted()`) and `app/Models/Company.php` (`gallery(): HasMany`, `type` cast to `OrganisationType`, `OrganisationType::Artisan` already exists from item 0.6).

Decision: **extend `CompanyGallery` additively**, not a new model. Its shape (one row per image, company-scoped, sort-ordered, plan-capped) already matches the "portfolio item" shape from the brief almost exactly — `caption` doubles as a title. Adding a parallel `Portfolio`/`PortfolioItem` model would duplicate the sort-order/plan-cap logic in `CompanyGallery::booted()` for no real benefit. Additive nullable columns added to `company_gallery`:

- `description` (text, nullable) — the project write-up.
- `materials_used` (string 255, nullable) — species/materials used (free text, comma-separated; no new taxonomy table — YAGNI for a first cut).
- `completed_on` (date, nullable) — project completion date.
- `is_portfolio` (boolean, default false) — distinguishes a portfolio piece from a plain marketing photo already using this table; existing rows and existing `CompanyGallery` consumers (product/marketing gallery) are unaffected since the flag defaults false and no existing column changes type or meaning.

`Company.php` gets exactly one additive method, `portfolioItems(): HasMany`, alongside the existing `gallery()` relation (no other line touched).

Public surface: `GET /companies/{slug}/portfolio` → `CompanyController::portfolio()` → `public.companies.portfolio` view, listing `is_portfolio = true` rows ordered by `sort_order`. Only reachable for companies with `type === OrganisationType::Artisan`; any other type (or `publiclyVisible()` failure) 404s, consistent with `CompanyController::show()`'s "do not disclose" pattern.

## File Structure

- Modify: `database/migrations/2026_08_29_100050_add_portfolio_fields_to_company_gallery_table.php` (new file) — additive nullable columns + `is_portfolio` boolean on `company_gallery`.
- Modify: `app/Models/CompanyGallery.php` — cast `completed_on` to `date`, cast `is_portfolio` to `boolean`, add a `scopePortfolio` query scope.
- Modify: `app/Models/Company.php` — one additive `portfolioItems(): HasMany` relation next to `gallery()`.
- Create: `app/Http/Controllers/Public/CompanyController.php` — add `portfolio(string $slug): View` action (new method, existing file/class already owned by this route surface).
- Create: `resources/views/public/companies/portfolio.blade.php` — portfolio grid view.
- Modify: `routes/web.php` — one additive route line for `companies.portfolio`.
- Test: `tests/Feature/CompanyPortfolioTest.php` (new).
- Test: `tests/Feature/CompanyGalleryPortfolioColumnsTest.php` (new, model-level).

## Tasks

### Task 1: Migration — additive portfolio columns on `company_gallery`

**Files:**
- Create: `database/migrations/2026_08_29_100050_add_portfolio_fields_to_company_gallery_table.php`
- Test: `tests/Feature/CompanyGalleryPortfolioColumnsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Company;
use App\Models\CompanyGallery;

it('stores portfolio metadata on a company gallery row', function () {
    $company = Company::factory()->create();

    $item = CompanyGallery::create([
        'company_id' => $company->id,
        'image_path' => 'gallery/carved-stool.jpg',
        'caption' => 'Hand-carved bubinga stool',
        'description' => 'A commissioned three-legged stool carved from reclaimed bubinga offcuts.',
        'materials_used' => 'Bubinga, beeswax finish',
        'completed_on' => '2026-03-15',
        'is_portfolio' => true,
        'sort_order' => 0,
    ]);

    expect($item->fresh())
        ->description->toBe('A commissioned three-legged stool carved from reclaimed bubinga offcuts.')
        ->materials_used->toBe('Bubinga, beeswax finish')
        ->is_portfolio->toBeTrue()
        ->and($item->fresh()->completed_on->toDateString())->toBe('2026-03-15');
});

it('defaults is_portfolio to false for plain marketing gallery rows', function () {
    $company = Company::factory()->create();

    $item = CompanyGallery::create([
        'company_id' => $company->id,
        'image_path' => 'gallery/showroom.jpg',
        'caption' => 'Our showroom',
        'sort_order' => 0,
    ]);

    expect($item->fresh()->is_portfolio)->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --env=testing --filter=CompanyGalleryPortfolioColumnsTest`
Expected: FAIL — `SQLSTATE[42703]: Undefined column: description` (or similar, columns don't exist yet).

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_gallery', function (Blueprint $table) {
            $table->text('description')->nullable()->after('caption');
            $table->string('materials_used', 255)->nullable()->after('description');
            $table->date('completed_on')->nullable()->after('materials_used');
            $table->boolean('is_portfolio')->default(false)->after('completed_on');
        });
    }

    public function down(): void
    {
        Schema::table('company_gallery', function (Blueprint $table) {
            $table->dropColumn(['description', 'materials_used', 'completed_on', 'is_portfolio']);
        });
    }
};
```

- [ ] **Step 4: Add casts to `CompanyGallery`**

In `app/Models/CompanyGallery.php`, add below the `protected $guarded = ['id'];` line:

```php
    protected function casts(): array
    {
        return [
            'completed_on' => 'date',
            'is_portfolio' => 'boolean',
        ];
    }

    public function scopePortfolio(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_portfolio', true);
    }
```

- [ ] **Step 5: Run migrations and the test**

Acquire the test-run lock first (see Coordination below). Then:

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan migrate --env=testing`
Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --env=testing --filter=CompanyGalleryPortfolioColumnsTest`
Expected: PASS (2 tests).

Release the lock immediately after.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_29_100050_add_portfolio_fields_to_company_gallery_table.php app/Models/CompanyGallery.php tests/Feature/CompanyGalleryPortfolioColumnsTest.php
git commit -m "feat: add portfolio fields to company_gallery"
```

### Task 2: `Company::portfolioItems()` relation

**Files:**
- Modify: `app/Models/Company.php` (read fresh immediately before editing — shared hotspot)
- Test: covered by Task 3's feature test (relation exercised through the controller/view)

- [ ] **Step 1: Read `app/Models/Company.php` fresh** to see the current state of the file (other agents may have added lines near `gallery()`).

- [ ] **Step 2: Add the relation immediately after the existing `gallery()` method**, without touching any other line:

```php
    public function portfolioItems(): HasMany
    {
        return $this->gallery()->portfolio();
    }
```

- [ ] **Step 3: Verify with tinker**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan --env=testing tinker --execute="echo (new App\Models\Company)->portfolioItems() instanceof Illuminate\Database\Eloquent\Relations\HasMany ? 'ok' : 'fail';"`
Expected: `ok`

- [ ] **Step 4: Commit**

```bash
git add app/Models/Company.php
git commit -m "feat: add Company::portfolioItems relation"
```

### Task 3: Public portfolio route, controller action, view

**Files:**
- Modify: `routes/web.php` (read fresh immediately before editing — shared hotspot; add one line only)
- Modify: `app/Http/Controllers/Public/CompanyController.php` (add one new method, do not touch `show()`)
- Create: `resources/views/public/companies/portfolio.blade.php`
- Test: `tests/Feature/CompanyPortfolioTest.php`

- [ ] **Step 1: Write the failing feature test**

```php
<?php

use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Models\Company;
use App\Models\CompanyGallery;

it('shows portfolio items for an artisan company', function () {
    $company = Company::factory()->publiclyVisible()->create([
        'type' => OrganisationType::Artisan,
    ]);

    CompanyGallery::create([
        'company_id' => $company->id,
        'image_path' => 'gallery/carved-stool.jpg',
        'caption' => 'Hand-carved bubinga stool',
        'description' => 'A commissioned three-legged stool carved from reclaimed bubinga offcuts.',
        'materials_used' => 'Bubinga, beeswax finish',
        'completed_on' => '2026-03-15',
        'is_portfolio' => true,
        'sort_order' => 0,
    ]);

    CompanyGallery::create([
        'company_id' => $company->id,
        'image_path' => 'gallery/showroom.jpg',
        'caption' => 'Our showroom',
        'is_portfolio' => false,
        'sort_order' => 1,
    ]);

    $response = $this->get(route('companies.portfolio', $company->slug));

    $response->assertOk();
    $response->assertSee('Hand-carved bubinga stool');
    $response->assertSee('Bubinga, beeswax finish');
    $response->assertDontSee('Our showroom');
});

it('404s the portfolio page for a non-artisan company', function () {
    $company = Company::factory()->publiclyVisible()->create([
        'type' => OrganisationType::Supplier,
    ]);

    $this->get(route('companies.portfolio', $company->slug))->assertNotFound();
});

it('404s the portfolio page for a company not publicly visible', function () {
    $company = Company::factory()->create([
        'status' => CompanyStatus::Draft,
        'type' => OrganisationType::Artisan,
    ]);

    $this->get(route('companies.portfolio', $company->slug))->assertNotFound();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --env=testing --filter=CompanyPortfolioTest`
Expected: FAIL — route `companies.portfolio` not defined.

- [ ] **Step 3: Read `routes/web.php` fresh**, then add one line immediately after the existing `companies.show` route (around line 46):

```php
Route::get('/companies/{slug}/portfolio', [CompanyController::class, 'portfolio'])->name('companies.portfolio');
```

- [ ] **Step 4: Add the controller action** to `app/Http/Controllers/Public/CompanyController.php` (new method, after `show()`):

```php
    public function portfolio(string $slug): View
    {
        $company = Company::publiclyVisible()
            ->where('slug', $slug)
            ->where('type', \App\Enums\OrganisationType::Artisan->value)
            ->with(['portfolioItems'])
            ->firstOrFail();

        return view('public.companies.portfolio', [
            'company' => $company,
            'items' => $company->portfolioItems,
        ]);
    }
```

- [ ] **Step 5: Create the view** `resources/views/public/companies/portfolio.blade.php`:

```blade
<x-app-layout>
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <a href="{{ route('companies.show', $company->slug) }}" class="text-sm text-forest-600 hover:underline">&larr; Back to {{ $company->name }}</a>
            <h1 class="mt-2 text-2xl font-semibold text-gray-900">{{ $company->name }} — Portfolio</h1>
            <p class="mt-1 text-sm text-gray-500">Completed work from this artisan.</p>
        </div>

        @if ($items->isEmpty())
            <p class="text-sm text-gray-500">No portfolio pieces have been added yet.</p>
        @else
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($items as $item)
                    <article class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                        <img src="{{ asset('storage/'.$item->image_path) }}" alt="{{ $item->alt_text ?? $item->caption }}" class="h-48 w-full object-cover">
                        <div class="p-4">
                            <h2 class="text-base font-semibold text-gray-900">{{ $item->caption }}</h2>
                            @if ($item->description)
                                <p class="mt-1 text-sm text-gray-600">{{ $item->description }}</p>
                            @endif
                            <dl class="mt-3 space-y-1 text-xs text-gray-500">
                                @if ($item->materials_used)
                                    <div><dt class="inline font-medium">Materials:</dt> <dd class="inline">{{ $item->materials_used }}</dd></div>
                                @endif
                                @if ($item->completed_on)
                                    <div><dt class="inline font-medium">Completed:</dt> <dd class="inline">{{ $item->completed_on->format('M Y') }}</dd></div>
                                @endif
                            </dl>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
```

- [ ] **Step 6: Run the test**

Acquire the test-run lock. Run: `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --env=testing --filter=CompanyPortfolioTest`
Expected: PASS (3 tests). Release the lock immediately after.

- [ ] **Step 7: Commit**

```bash
git add routes/web.php app/Http/Controllers/Public/CompanyController.php resources/views/public/companies/portfolio.blade.php tests/Feature/CompanyPortfolioTest.php
git commit -m "feat: add public artisan portfolio page"
```

### Task 4: Full-suite verification and GAP_PLAN.md update

- [ ] **Step 1:** Acquire the test-run lock, run `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe artisan test --env=testing`, record the pass count, release the lock.
- [ ] **Step 2:** Acquire the GAP_PLAN.md lock, re-read `docs/GAP_PLAN.md` fresh, mark only the 1.5.8 row as done with a one-line note, commit, release the lock.
- [ ] **Step 3: Run `vendor/bin/pint --dirty`** before this final commit if not already run per-task.

## Coordination Notes (shared worktree)

- **Test-run lock:** `mkdir .test.lock` (atomic) before any `artisan test`/`artisan migrate --env=testing` command; write `holder.txt`; run exactly one command; `rm -rf .test.lock` immediately after. On failure, wait ~10s and retry, up to ~150 attempts. Treat a `holder.txt` older than 15 minutes as stale and remove it.
- **GAP_PLAN.md lock:** same mechanism, scoped to editing the 1.5.8 row only.
- **`Company.php` and `routes/web.php` are shared hotspots** — re-read fresh immediately before every edit; add lines alongside existing content; never overwrite.
- **Module boundary:** touch only `database/migrations/2026_08_29_100050_*`, `app/Models/CompanyGallery.php`, `app/Models/Company.php` (one relation only), `app/Http/Controllers/Public/CompanyController.php` (one new method), `resources/views/public/companies/portfolio.blade.php`, `routes/web.php` (one line), and the two new test files. Do not touch `Product.php`, `RfqType.php`, `Rfq.php`, `Category*`, `Certificate*`, `ContactMessage*`, `Document*`, `ChainedActivity*`, `Consent*`, `Inventory*`, `BadgeType.php`/`BadgeService.php`.

## Self-Review

**Spec coverage:** title (`caption`, existing) ✓, description (new) ✓, images (existing `image_path`) ✓, species/materials used (new `materials_used`) ✓, completion date (new `completed_on`) ✓, public artisan-profile display (new route/controller/view) ✓, Artisan-gated (`OrganisationType::Artisan` check + 404 for others) ✓, mirrors `CompanyGallery` house style rather than inventing a parallel system ✓.

**Placeholder scan:** no TBD/TODO; every step has complete code.

**Type consistency:** `portfolioItems(): HasMany` on `Company` returns `$this->gallery()->portfolio()` — `gallery()` already returns `HasMany<CompanyGallery>`, and `scopePortfolio` is defined as a query scope on `CompanyGallery`, chainable on a `HasMany` builder — consistent throughout. Controller uses `$company->portfolioItems` (property access, triggers the relation) matching the eager-load key `'portfolioItems'` in `with([...])`. View accesses `$item->caption`, `$item->description`, `$item->materials_used`, `$item->completed_on`, `$item->image_path`, `$item->alt_text` — all match the migration's new/existing columns.
