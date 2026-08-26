# SEO/AI Authority — Phase 1 (First 30 Days, P0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land the P0 slice of `docs/superpowers/specs/2026-08-26-seo-ai-authority-architecture.md`: verify and commit the existing `/insights` editorial platform, add `/llms.txt`, extend `Species` with the Knowledge System fields from spec §C, ship the Glossary system, and publish one fully real, sourced Compliance article end-to-end as proof the pipeline works.

**Architecture:** Extend what already exists rather than rebuild. The uncommitted `Article`/`ArticleCategory`/`ArticleBody`/`InsightController` platform already implements most of spec §M/§N/§G (markdown storage, XSS-safe rendering, E-E-A-T author discipline, FAQPage-only-when-real, the internal-link scheme). This plan's job is: (1) prove that platform is solid and commit it, (2) fill the specific P0 gaps — `llms.txt`, Species fields, Glossary — following its exact conventions, (3) prove the whole thing end-to-end with one real published article.

**Tech Stack:** Laravel 13, Filament 5, PostgreSQL (FTS + trigram), Pest.

Spec: `docs/superpowers/specs/2026-08-26-seo-ai-authority-architecture.md`

**Explicitly out of scope for this plan** (deferred per the spec's own 60/90-day phasing, or genuinely not yet needed):
- Sitemap segmentation into per-type files (spec §A) — the combined sitemap currently lists on the order of 150 URLs total; the 50k-URL split threshold is not close. Revisit once Species + Article + GlossaryTerm + Product volume actually approaches it.
- Full 17-hub Knowledge Centre build-out, calculators, courses, French `/fr` tree, Price Index, `search-intents.csv` research pass — all explicitly 60-day/90-day/6-month items in spec §O.
- Remaining Compliance-hub articles beyond the one built here (FLEGT, SIGIF II, chain of custody, etc.) — this plan proves the pipeline with one real article; the remaining ~11-14 are an editorial content pass using the now-proven pipeline, not a code task.

---

### Task 1: Verify and commit the existing `/insights` platform

**Files:**
- No new files. Verifying: `app/Models/Article.php`, `app/Enums/ArticleCategory.php`, `app/Enums/ArticleStatus.php`, `app/Support/ArticleBody.php`, `app/Http/Controllers/Public/InsightController.php`, `app/Filament/Resources/Articles/**`, `app/Console/Commands/ImportArticles.php`, `app/Models/AppLaunchSubscriber.php`, `app/Http/Controllers/Public/MobileAppController.php`, `config/mobile.php`, `database/migrations/2026_08_25_090010_create_app_launch_subscribers_table.php`, `database/migrations/2026_08_25_100010_create_articles_table.php`, `database/factories/ArticleFactory.php`, `resources/views/components/article-card.blade.php`, `resources/views/public/insights/**`, `content/**`, `tests/Feature/Article*.php`.

- [ ] **Step 1: Confirm the worktree is on the expected base and nothing else is pending**

Run: `git -C "C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter" log --oneline -1`
Expected: `e5ccbd2 Close four buyer-API contract defects found by a real client` (or later — if a newer commit exists, that's fine, this task still applies to whatever is currently uncommitted on top of it).

- [ ] **Step 2: Run the full test suite to establish a baseline**

Run (from the worktree, with PHP/Composer on PATH):
```bash
export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:/c/laragon/bin/composer:$PATH"
cd "C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter"
php artisan test
```
Expected: PASS. If `tests/Feature/ArticleTest.php`, `ArticleAdminTest.php`, or `ArticleImportTest.php` fail, read the failure, fix the underlying code (not the test, unless the test is asserting something the spec doesn't require), and re-run until green. Do not proceed to Step 3 with a red suite.

- [ ] **Step 3: Confirm migrations run cleanly**

Run:
```bash
php artisan migrate --force
```
Expected: `2026_08_25_090010_create_app_launch_subscribers_table` and `2026_08_25_100010_create_articles_table` apply with no errors.

- [ ] **Step 4: Spot-check the mobile-app page and insights index render**

Run:
```bash
php artisan serve --port=8000 &
sleep 2
curl -s -o /dev/null -w "insights:%{http_code}\n" http://127.0.0.1:8000/insights
curl -s -o /dev/null -w "mobile-app:%{http_code}\n" http://127.0.0.1:8000/mobile-app
```
Expected: both `200`.

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "Add the /insights editorial platform: Article model, importer, Filament resource, mobile-app download page"
```

---

### Task 2: `/llms.txt`

**Files:**
- Modify: `app/Http/Controllers/Public/SitemapController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/LlmsTxtTest.php`

`/llms.txt` is a plain-text index of the site's key content for LLM consumption (spec §G). It must be generated from real published routes, never hand-maintained, so it cannot drift stale.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ArticleCategory;
use App\Models\Article;
use App\Models\Species;

it('serves llms.txt listing real published content', function () {
    Species::factory()->create(['common_name' => 'Iroko', 'slug' => 'iroko-llms-test', 'is_published' => true]);
    Article::factory()->create(['title' => 'How to verify a Cameroon timber supplier', 'slug' => 'how-to-verify-a-supplier-llms-test']);

    $response = $this->get('/llms.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    $body = $response->getContent();

    expect($body)
        ->toContain('Cameroon Timber Hub')
        ->toContain(route('species.show', 'iroko-llms-test'))
        ->toContain(route('insights.show', 'how-to-verify-a-supplier-llms-test'));
});

it('excludes unpublished content from llms.txt', function () {
    Species::factory()->create(['common_name' => 'Hidden', 'slug' => 'hidden-llms-test', 'is_published' => false]);

    $this->get('/llms.txt')->assertDontSee('hidden-llms-test');
});

it('links llms.txt from robots.txt', function () {
    $this->get('/robots.txt')->assertSee(url('/llms.txt'), false);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/LlmsTxtTest.php`
Expected: FAIL — route `/llms.txt` does not exist (404).

- [ ] **Step 3: Add the route**

In `routes/web.php`, near the existing `sitemap`/`robots` routes:

```php
Route::get('/llms.txt', [SitemapController::class, 'llmsTxt'])->name('llms-txt');
```

- [ ] **Step 4: Implement the controller method**

Add to `app/Http/Controllers/Public/SitemapController.php` (same class already imports `Article`, `Company`, `Page`, `Species`):

```php
    /**
     * llms.txt — a plain-text index of the site's key content for LLM
     * consumption, generated from real published routes only. Never hand
     * maintained: every line here is derived the same way the XML sitemap
     * derives its <url> list, so the two cannot drift apart.
     */
    public function llmsTxt(): Response
    {
        $lines = [
            '# Cameroon Timber Hub',
            '',
            '> B2B marketplace connecting verified Cameroonian timber suppliers with',
            '> international buyers — species data, supplier verification, RFQs and',
            '> compliance guidance for the Cameroon timber trade.',
            '',
            '## Marketplace',
            '- ['.route('directory').'](Verified Cameroon timber suppliers)',
            '- ['.route('species.index').'](Cameroon timber species directory)',
            '',
        ];

        $species = Species::published()->orderBy('common_name')->get(['slug', 'common_name', 'scientific_name']);

        if ($species->isNotEmpty()) {
            $lines[] = '## Timber Species';
            foreach ($species as $item) {
                $label = $item->scientific_name ? "{$item->common_name} ({$item->scientific_name})" : $item->common_name;
                $lines[] = '- ['.route('species.show', $item->slug)."]({$label})";
            }
            $lines[] = '';
        }

        $articles = Article::published()->orderByDesc('published_at')->get(['slug', 'title', 'excerpt']);

        if ($articles->isNotEmpty()) {
            $lines[] = '## Guides & Compliance';
            foreach ($articles as $article) {
                $lines[] = '- ['.route('insights.show', $article->slug)."]({$article->title})";
            }
            $lines[] = '';
        }

        $companyCount = Company::publiclyVisible()->count();

        if ($companyCount > 0) {
            $lines[] = '## Verified Suppliers';
            $lines[] = "- {$companyCount} verified suppliers listed at ".route('directory');
            $lines[] = '';
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
```

- [ ] **Step 5: Link it from robots.txt**

Find the `robots()` method in `app/Http/Controllers/Public/SitemapController.php` — it already builds `$lines` and mentions advertising `/llms.txt` alongside the sitemap in its docblock. Locate where it appends the sitemap URL (near the end of `$lines`, before the response is returned) and add, immediately after that line:

```php
            'Sitemap: '.route('sitemap'),
            '',
            '# LLM-readable content index',
            'Sitemap: '.url('/llms.txt'),
```

(Match this against the method's actual existing final lines — insert the `/llms.txt` reference right after wherever the sitemap URL is already emitted, keeping the existing `Sitemap:` line intact.)

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/LlmsTxtTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Full suite + commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Http/Controllers/Public/SitemapController.php routes/web.php tests/Feature/LlmsTxtTest.php
git commit -m "Add /llms.txt: a real-content index for LLM retrieval, linked from robots.txt"
```

---

### Task 3: Extend `Species` with the Knowledge System fields (spec §C)

**Files:**
- Create: `database/migrations/2026_08_27_100010_add_knowledge_system_fields_to_species_table.php`
- Modify: `app/Models/Species.php`
- Modify: `app/Filament/Resources/Species/Schemas/SpeciesForm.php`
- Test: `tests/Feature/SpeciesKnowledgeFieldsTest.php`

Schema-only for this task — content backfill is progressive (spec §O, 30-day scope explicitly says "schema only; content backfilled progressively"). Every new field is nullable and defaults to absent, matching the existing `log_export_status = Unknown`-by-default discipline already established on this model.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Species;

it('persists the knowledge-system fields added for the SEO authority spec', function () {
    $species = Species::factory()->create([
        'french_name' => 'Iroko',
        'taxonomy' => ['family' => 'Moraceae', 'genus' => 'Milicia', 'species' => 'excelsa', 'order' => 'Rosales'],
        'workability' => 'Works well with both hand and machine tools; moderate blunting effect on cutters.',
        'drying_behaviour' => 'Dries slowly with little degrade; low shrinkage.',
        'treatments' => ['Kiln drying', 'Preservative treatment rarely required (naturally durable)'],
        'grades_available' => ['FAS', 'Select', 'Standard'],
        'eudr_risk_note' => null,
        'authoritative_sources' => [
            ['title' => 'CITES Species Database', 'publisher' => 'CITES Secretariat', 'url' => 'https://cites.org', 'accessed_date' => '2026-08-01'],
        ],
    ]);

    $fresh = $species->fresh();

    expect($fresh->french_name)->toBe('Iroko')
        ->and($fresh->taxonomy)->toBe(['family' => 'Moraceae', 'genus' => 'Milicia', 'species' => 'excelsa', 'order' => 'Rosales'])
        ->and($fresh->workability)->toContain('Works well')
        ->and($fresh->treatments)->toBeArray()
        ->and($fresh->grades_available)->toBe(['FAS', 'Select', 'Standard'])
        ->and($fresh->eudr_risk_note)->toBeNull()
        ->and($fresh->authoritative_sources)->toHaveCount(1);
});

it('defaults every new knowledge-system field to null, never a fabricated placeholder', function () {
    $species = Species::factory()->create();

    expect($species->fresh())
        ->french_name->toBeNull()
        ->taxonomy->toBeNull()
        ->workability->toBeNull()
        ->drying_behaviour->toBeNull()
        ->treatments->toBeNull()
        ->grades_available->toBeNull()
        ->eudr_risk_note->toBeNull()
        ->authoritative_sources->toBeNull();
});

it('renders an honest not-yet-assessed note when eudr_risk_note is null', function () {
    $species = Species::factory()->create(['slug' => 'eudr-note-test', 'is_published' => true]);

    $this->get(route('species.show', $species->slug))
        ->assertOk()
        ->assertSee('not yet assessed', false);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/SpeciesKnowledgeFieldsTest.php`
Expected: FAIL — unknown column `french_name` (or similar) on the `species` table.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Species Knowledge System fields — spec §C
 * (docs/superpowers/specs/2026-08-26-seo-ai-authority-architecture.md).
 *
 * Every field is nullable with no default value. A null `eudr_risk_note` is
 * not "unknown data we forgot to fill in" — it is the honest state until a
 * genuinely sourced, dated regulatory assessment exists, exactly like the
 * existing `log_export_status = Unknown` default on this table. Nothing here
 * is backfilled with placeholder or inferred content by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('species', function (Blueprint $table) {
            $table->string('french_name', 150)->nullable()->after('scientific_name');
            $table->jsonb('taxonomy')->nullable()->after('family');
            $table->text('workability')->nullable()->after('characteristics');
            $table->text('drying_behaviour')->nullable()->after('workability');
            $table->jsonb('treatments')->nullable()->after('drying_behaviour');
            $table->jsonb('grades_available')->nullable()->after('treatments');
            $table->text('eudr_risk_note')->nullable()->after('log_export_status');
            $table->jsonb('authoritative_sources')->nullable()->after('meta_description');
        });
    }

    public function down(): void
    {
        Schema::table('species', function (Blueprint $table) {
            $table->dropColumn([
                'french_name',
                'taxonomy',
                'workability',
                'drying_behaviour',
                'treatments',
                'grades_available',
                'eudr_risk_note',
                'authoritative_sources',
            ]);
        });
    }
};
```

- [ ] **Step 4: Add the casts to `Species`**

Open `app/Models/Species.php`, find the `casts()` method, add these four lines to the returned array (alongside the existing `local_names`, `trade_names`, `characteristics`, `typical_uses`, `region_availability` jsonb casts):

```php
            'taxonomy' => 'array',
            'treatments' => 'array',
            'grades_available' => 'array',
            'authoritative_sources' => 'array',
```

(`french_name`, `workability`, `drying_behaviour`, `eudr_risk_note` are plain strings — no cast needed, same as the existing `description`/`durability_class` fields.)

- [ ] **Step 5: Render the honest EUDR fallback on the species detail view**

Find where the species detail Blade view (`resources/views/public/species/show.blade.php`) renders the existing classification block (density/durability/CITES). Add, in that same block:

```blade
<div>
    <dt class="font-semibold text-ink">EUDR risk assessment</dt>
    <dd class="text-ink-soft">
        @if ($species->eudr_risk_note)
            {{ $species->eudr_risk_note }}
        @else
            Not yet assessed on this platform — consult current EU Deforestation Regulation guidance directly.
        @endif
    </dd>
</div>
```

(Match the surrounding markup's existing `<dt>`/`<dd>` or card pattern rather than introducing a new structure — this snippet shows the content and the exact fallback copy the test asserts on; place it consistently with how `log_export_status` is already rendered nearby.)

- [ ] **Step 6: Add the new fields to the Filament Species form**

Open `app/Filament/Resources/Species/Schemas/SpeciesForm.php`. Find the section containing `commercial_category`/`is_promoted`/`log_export_status` (the existing Cameroon classification section) and add a new section after it:

```php
Section::make('Knowledge System (SEO authority spec §C)')
    ->description('Schema.org DefinedTerm data and sourced facts for /wood-species pages. Leave a field blank rather than guessing — a null value renders an honest "not yet assessed" note instead of a fabricated claim.')
    ->schema([
        TextInput::make('french_name')->label('French trade name')->maxLength(150),
        Textarea::make('workability')->rows(2),
        Textarea::make('drying_behaviour')->rows(2),
        Textarea::make('eudr_risk_note')->label('EUDR risk note')->rows(3)
            ->helperText('Only populate with a genuinely sourced, dated regulatory assessment. Leave blank otherwise.'),
        TagsInput::make('grades_available')->placeholder('FAS, Select, Standard'),
        TagsInput::make('treatments')->placeholder('Kiln drying, Preservative treatment'),
        Repeater::make('authoritative_sources')
            ->schema([
                TextInput::make('title')->required(),
                TextInput::make('publisher')->required(),
                TextInput::make('url')->url()->required(),
                DatePicker::make('accessed_date')->required(),
            ])
            ->columns(2)
            ->collapsible(),
    ])
    ->columns(2),
```

Add the needed `use` statements at the top of the file if not already present: `Filament\Forms\Components\TagsInput`, `Filament\Forms\Components\Repeater`, `Filament\Forms\Components\DatePicker` (check the file first — `TextInput`, `Textarea`, `Section` are almost certainly already imported given the existing form).

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test tests/Feature/SpeciesKnowledgeFieldsTest.php`
Expected: PASS (3 tests).

- [ ] **Step 8: Full suite, migrate, Pint, commit**

```bash
php artisan migrate --force
php artisan test
vendor/bin/pint --dirty
git add database/migrations/2026_08_27_100010_add_knowledge_system_fields_to_species_table.php app/Models/Species.php app/Filament/Resources/Species/Schemas/SpeciesForm.php resources/views/public/species/show.blade.php tests/Feature/SpeciesKnowledgeFieldsTest.php
git commit -m "Extend Species with the Knowledge System fields from the SEO authority spec (§C)"
```

---

### Task 4: `GlossaryTerm` — model, migration, public routes, Filament resource

**Files:**
- Create: `database/migrations/2026_08_27_110010_create_glossary_terms_table.php`
- Create: `app/Models/GlossaryTerm.php`
- Create: `database/factories/GlossaryTermFactory.php`
- Create: `app/Http/Controllers/Public/GlossaryController.php`
- Create: `resources/views/public/glossary/index.blade.php`
- Create: `resources/views/public/glossary/show.blade.php`
- Create: `app/Filament/Resources/GlossaryTerms/GlossaryTermResource.php`
- Create: `app/Filament/Resources/GlossaryTerms/Pages/{ListGlossaryTerms,CreateGlossaryTerm,EditGlossaryTerm}.php`
- Create: `app/Filament/Resources/GlossaryTerms/Schemas/GlossaryTermForm.php`
- Create: `app/Filament/Resources/GlossaryTerms/Tables/GlossaryTermsTable.php`
- Create: `database/seeders/GlossaryTermSeeder.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Public/SitemapController.php` (add glossary terms to the sitemap + llms.txt)
- Modify: `resources/views/components/layouts/app.blade.php` (add a Glossary footer link)
- Test: `tests/Feature/GlossaryTest.php`

Follow `Species`/`Article`'s exact existing conventions: `HasSlug`, `published()` scope, FTS `search_vector`, `BreadcrumbList` + `DefinedTerm` JSON-LD.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\GlossaryTerm;

it('lists published glossary terms alphabetically', function () {
    GlossaryTerm::factory()->create(['term' => 'Board Foot', 'slug' => 'board-foot', 'is_published' => true]);
    GlossaryTerm::factory()->create(['term' => 'Air Drying', 'slug' => 'air-drying', 'is_published' => true]);
    GlossaryTerm::factory()->create(['term' => 'Chain of Custody', 'slug' => 'chain-of-custody', 'is_published' => true]);

    $response = $this->get(route('glossary.index'))->assertOk();

    $body = $response->getContent();
    $airPos = strpos($body, 'Air Drying');
    $boardPos = strpos($body, 'Board Foot');
    $chainPos = strpos($body, 'Chain of Custody');

    expect($airPos)->toBeLessThan($boardPos)
        ->and($boardPos)->toBeLessThan($chainPos);
});

it('never lists an unpublished term', function () {
    GlossaryTerm::factory()->create(['term' => 'Draft Term', 'slug' => 'draft-term', 'is_published' => false]);

    $this->get(route('glossary.index'))->assertOk()->assertDontSee('Draft Term');
});

it('shows a single term with definition, related terms and DefinedTerm schema', function () {
    $related = GlossaryTerm::factory()->create(['term' => 'CBM', 'slug' => 'cbm', 'is_published' => true]);
    $term = GlossaryTerm::factory()->create([
        'term' => 'Boules',
        'slug' => 'boules',
        'definition' => 'A log sawn through-and-through and kept in sequence so it can be reassembled.',
        'related_term_ids' => [$related->id],
        'is_published' => true,
    ]);

    $response = $this->get(route('glossary.show', 'boules'))
        ->assertOk()
        ->assertSee('Boules')
        ->assertSee('reassembled', false)
        ->assertSee('CBM');

    $response->assertSee('DefinedTerm', false);
});

it('404s an unpublished or unknown term', function () {
    GlossaryTerm::factory()->create(['slug' => 'hidden-term', 'is_published' => false]);

    $this->get(route('glossary.show', 'hidden-term'))->assertNotFound();
    $this->get(route('glossary.show', 'does-not-exist'))->assertNotFound();
});

it('is searchable by term via full text search', function () {
    GlossaryTerm::factory()->create(['term' => 'Kiln Drying', 'slug' => 'kiln-drying', 'is_published' => true]);
    GlossaryTerm::factory()->create(['term' => 'Air Drying', 'slug' => 'air-drying-2', 'is_published' => true]);

    $this->get(route('glossary.index', ['q' => 'kiln']))
        ->assertOk()
        ->assertSee('Kiln Drying')
        ->assertDontSee('Air Drying');
});

it('includes published glossary terms in the sitemap and llms.txt', function () {
    GlossaryTerm::factory()->create(['term' => 'FOB', 'slug' => 'fob-sitemap-test', 'is_published' => true]);

    $this->get(route('sitemap'))->assertOk()->assertSee(route('glossary.show', 'fob-sitemap-test'), false);
    $this->get('/llms.txt')->assertOk()->assertSee(route('glossary.show', 'fob-sitemap-test'), false);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/GlossaryTest.php`
Expected: FAIL — `Class "App\Models\GlossaryTerm" not found`.

- [ ] **Step 3: Write the migration**

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
        Schema::create('glossary_terms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('slug', 180)->unique();
            $table->string('term', 150);
            $table->string('french_term', 150)->nullable();
            $table->text('definition');
            $table->text('explanation')->nullable();
            $table->jsonb('related_term_ids')->nullable();
            $table->jsonb('related_species_ids')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->boolean('is_published')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->index('is_published');
            $table->index('term');
        });

        // Weighted FTS vector, mirroring species/articles.
        DB::statement("ALTER TABLE glossary_terms ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
            setweight(to_tsvector('english', coalesce(term, '')), 'A') ||
            setweight(to_tsvector('english', coalesce(definition, '')), 'B') ||
            setweight(to_tsvector('english', coalesce(explanation, '')), 'C')
        ) STORED");

        DB::statement('CREATE INDEX glossary_terms_search_vector_gin ON glossary_terms USING gin (search_vector)');
        DB::statement('CREATE INDEX glossary_terms_term_trgm ON glossary_terms USING gin (term gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('glossary_terms');
    }
};
```

- [ ] **Step 4: The model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One term in the public Cameroon timber glossary (/knowledge/glossary).
 *
 * Deliberately flat and simple — this is a dictionary, not an article. Every
 * term links out to real related terms/species via id arrays, resolved at
 * render time, exactly like Article::relatedSpecies().
 */
class GlossaryTerm extends Model
{
    use HasFactory, HasSlug;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'related_term_ids' => 'array',
            'related_species_ids' => 'array',
            'is_published' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function slugSourceColumn(): string
    {
        return 'term';
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeSearch(Builder $query, string $q): Builder
    {
        $q = trim($q);

        if ($q === '') {
            return $query;
        }

        return $query->where(fn (Builder $w) => $w
            ->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$q])
            ->orWhereRaw('term % ?', [$q]));
    }

    /** @return Collection<int, GlossaryTerm> */
    public function relatedTerms(): Collection
    {
        if (! $this->related_term_ids) {
            return collect();
        }

        return static::published()->whereIn('id', $this->related_term_ids)->orderBy('term')->get();
    }

    /** @return Collection<int, Species> */
    public function relatedSpecies(): Collection
    {
        if (! $this->related_species_ids) {
            return collect();
        }

        return Species::published()->whereIn('id', $this->related_species_ids)->orderBy('common_name')->get();
    }

    public function url(): string
    {
        return route('glossary.show', $this->slug);
    }
}
```

- [ ] **Step 5: Factory**

```php
<?php

namespace Database\Factories;

use App\Models\GlossaryTerm;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GlossaryTerm>
 */
class GlossaryTermFactory extends Factory
{
    protected $model = GlossaryTerm::class;

    public function definition(): array
    {
        $term = ucfirst($this->faker->unique()->words(2, true));

        return [
            'slug' => Str::slug($term).'-'.$this->faker->unique()->numberBetween(1, 999999),
            'term' => $term,
            'definition' => $this->faker->sentence(12),
            'explanation' => $this->faker->paragraph(),
            'related_term_ids' => [],
            'related_species_ids' => [],
            'is_published' => true,
        ];
    }
}
```

- [ ] **Step 6: Public controller**

```php
<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\GlossaryTerm;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /knowledge/glossary — the Cameroon timber glossary.
 *
 * Every term reads through GlossaryTerm::published(), the single visibility
 * gate: an unpublished term is unreachable from the index, from a direct
 * slug, from search, from the sitemap and from /llms.txt.
 */
class GlossaryController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $terms = GlossaryTerm::published()
            ->search($q)
            ->orderBy('term')
            ->get()
            ->groupBy(fn (GlossaryTerm $t) => strtoupper(substr($t->term, 0, 1)));

        return view('public.glossary.index', [
            'terms' => $terms,
            'q' => $q,
            'total' => GlossaryTerm::published()->count(),
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Glossary', 'url' => route('glossary.index')],
            ],
        ]);
    }

    public function show(string $slug): View
    {
        $term = GlossaryTerm::published()->where('slug', $slug)->firstOrFail();

        return view('public.glossary.show', [
            'term' => $term,
            'relatedTerms' => $term->relatedTerms(),
            'relatedSpecies' => $term->relatedSpecies(),
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Glossary', 'url' => route('glossary.index')],
                ['label' => $term->term, 'url' => $term->url()],
            ],
            'schema' => $this->termSchema($term),
        ]);
    }

    /** @return array<string, mixed> */
    private function termSchema(GlossaryTerm $term): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'DefinedTerm',
            '@id' => $term->url().'#term',
            'name' => $term->term,
            'description' => $term->definition,
            'inDefinedTermSet' => [
                '@type' => 'DefinedTermSet',
                '@id' => route('glossary.index').'#glossary',
                'name' => 'Cameroon Timber Hub Glossary',
            ],
            'url' => $term->url(),
        ];
    }
}
```

- [ ] **Step 7: Public views**

`resources/views/public/glossary/index.blade.php`:

```blade
<x-layouts.app
    title="Cameroon Timber Glossary"
    description="Definitions for Cameroon timber trade terms — grading, shipping, compliance and species terminology explained."
    :breadcrumbs="$breadcrumbs">

    <section class="mx-auto max-w-4xl px-4 py-10">
        <h1 class="font-display text-3xl font-semibold text-forest-950">Cameroon Timber Glossary</h1>
        <p class="mt-2 text-[1.0625rem] text-ink-soft">{{ $total }} terms defined.</p>

        <form method="GET" action="{{ route('glossary.index') }}" class="mt-6">
            <label for="glossary-q" class="sr-only">Search the glossary</label>
            <input id="glossary-q" type="search" name="q" value="{{ $q }}"
                   placeholder="Search timber terms…"
                   class="w-full max-w-md rounded-lg border border-sand-300 px-3 py-2 text-[1.0625rem]">
        </form>

        @forelse ($terms as $letter => $group)
            <div class="mt-8">
                <h2 class="font-display text-xl font-semibold text-forest-800">{{ $letter }}</h2>
                <ul class="mt-3 space-y-2">
                    @foreach ($group as $term)
                        <li>
                            <a href="{{ $term->url() }}" class="text-[1.0625rem] font-medium text-forest-700 hover:underline">{{ $term->term }}</a>
                            <span class="ml-2 text-[0.9375rem] text-ink-soft">{{ Str::limit($term->definition, 80) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <p class="mt-8 text-ink-soft">No terms match "{{ $q }}".</p>
        @endforelse
    </section>
</x-layouts.app>
```

`resources/views/public/glossary/show.blade.php`:

```blade
<x-layouts.app
    :title="$term->term"
    :description="$term->meta_description ?: $term->definition"
    :schema="$schema"
    :breadcrumbs="$breadcrumbs">

    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

    <section class="mx-auto max-w-3xl px-4 py-10">
        <h1 class="font-display text-3xl font-semibold text-forest-950">{{ $term->term }}</h1>
        @if ($term->french_term)
            <p class="mt-1 text-[1.0625rem] text-ink-soft">French: {{ $term->french_term }}</p>
        @endif

        <p class="mt-5 text-[1.125rem] leading-relaxed text-ink">{{ $term->definition }}</p>

        @if ($term->explanation)
            <div class="mt-4 text-[1.0625rem] leading-relaxed text-ink-soft">{{ $term->explanation }}</div>
        @endif

        @if ($relatedTerms->isNotEmpty())
            <div class="mt-8">
                <h2 class="font-display text-lg font-semibold text-forest-800">Related terms</h2>
                <ul class="mt-2 flex flex-wrap gap-2">
                    @foreach ($relatedTerms as $related)
                        <li><a href="{{ $related->url() }}" class="rounded-full border border-sand-300 px-3 py-1 text-[0.9375rem] text-forest-700 hover:border-forest-400">{{ $related->term }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($relatedSpecies->isNotEmpty())
            <div class="mt-6">
                <h2 class="font-display text-lg font-semibold text-forest-800">Related species</h2>
                <ul class="mt-2 flex flex-wrap gap-2">
                    @foreach ($relatedSpecies as $species)
                        <li><a href="{{ route('species.show', $species->slug) }}" class="rounded-full border border-sand-300 px-3 py-1 text-[0.9375rem] text-forest-700 hover:border-forest-400">{{ $species->common_name }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>
</x-layouts.app>
```

- [ ] **Step 8: Routes**

In `routes/web.php`, add near the existing `species.*`/`insights.*` routes:

```php
Route::get('/knowledge/glossary', [GlossaryController::class, 'index'])->name('glossary.index');
Route::get('/knowledge/glossary/{slug}', [GlossaryController::class, 'show'])->name('glossary.show');
```

Add the `use App\Http\Controllers\Public\GlossaryController;` import at the top of the file.

- [ ] **Step 9: Wire into the sitemap and llms.txt**

In `app/Http/Controllers/Public/SitemapController.php`:
- Add `use App\Models\GlossaryTerm;` to the imports.
- In `index()`, after the Article loop, add:

```php
        foreach (GlossaryTerm::published()->get(['slug', 'updated_at']) as $term) {
            $urls[] = [
                'loc' => route('glossary.show', $term->slug),
                'lastmod' => optional($term->updated_at)->toAtomString(),
                'priority' => '0.5',
            ];
        }
```

- In `llmsTxt()` (Task 2), after the Articles block, add:

```php
        $terms = GlossaryTerm::published()->orderBy('term')->get(['slug', 'term']);

        if ($terms->isNotEmpty()) {
            $lines[] = '## Glossary';
            foreach ($terms as $term) {
                $lines[] = '- ['.route('glossary.show', $term->slug)."]({$term->term})";
            }
            $lines[] = '';
        }
```

- [ ] **Step 10: Footer link**

In `resources/views/components/layouts/app.blade.php`, find the `$resources` array that already lists Timber Grades / Export Guide / Market Insights / Blog and add:

```php
        ['label' => 'Glossary', 'url' => route('glossary.index')],
```

- [ ] **Step 11: Filament resource — follow the existing `ArticleResource` shape exactly**

Read `app/Filament/Resources/Articles/ArticleResource.php`, `Schemas/ArticleForm.php`, `Tables/ArticlesTable.php`, and `Pages/*.php` first, then create the `GlossaryTerms` equivalent mirroring their structure field-for-field (list/create/edit pages, a form with the model's real fields, a table with `term`, `is_published`, `updated_at` columns and a search-by-term filter). This step has no separate code block because it must match whatever conventions those existing files use exactly — copy their pattern, not a diverging one.

- [ ] **Step 12: Seeder — first batch of real, correct core terms**

```php
<?php

namespace Database\Seeders;

use App\Models\GlossaryTerm;
use Illuminate\Database\Seeder;

/**
 * The first batch of the glossary — genuinely correct, standard timber-trade
 * definitions, not placeholder text. Idempotent via updateOrCreate on slug.
 * Grows toward the spec's 150-250 term target over subsequent editorial
 * passes; this seeder is the P0 core set, not the finished glossary.
 */
class GlossaryTermSeeder extends Seeder
{
    public function run(): void
    {
        $terms = [
            ['slug' => 'cbm', 'term' => 'CBM', 'definition' => 'Cubic metre — the standard unit of volume used to measure and price timber shipments.'],
            ['slug' => 'fob', 'term' => 'FOB', 'definition' => 'Free On Board — an Incoterm under which the seller\'s responsibility ends once goods are loaded onto the vessel at the port of export.'],
            ['slug' => 'cif', 'term' => 'CIF', 'definition' => 'Cost, Insurance and Freight — an Incoterm under which the seller pays for shipping and insurance to the destination port.'],
            ['slug' => 'kiln-dried', 'term' => 'Kiln Dried (KD)', 'definition' => 'Timber dried in a controlled-temperature chamber to a specified target moisture content, faster and more consistent than air drying.'],
            ['slug' => 'air-dried', 'term' => 'Air Dried (AD)', 'definition' => 'Timber dried naturally by stacking with airflow between boards, without mechanical heat.'],
            ['slug' => 'moisture-content', 'term' => 'Moisture Content', 'definition' => 'The weight of water in a piece of timber expressed as a percentage of its oven-dry weight; a key factor in stability and suitability for use.'],
            ['slug' => 'boules', 'term' => 'Boules', 'definition' => 'A log sawn through-and-through into boards that are kept in their original sawing sequence so the log can be reassembled — used to match grain across a project.'],
            ['slug' => 'sapwood', 'term' => 'Sapwood', 'definition' => 'The outer, living layer of wood in a growing tree, typically lighter in colour and less durable than heartwood.'],
            ['slug' => 'heartwood', 'term' => 'Heartwood', 'definition' => 'The inner, structurally inactive wood of a tree, generally denser, darker and more durable than sapwood.'],
            ['slug' => 'chain-of-custody', 'term' => 'Chain of Custody', 'definition' => 'The documented, traceable path of timber from harvest through processing and export to the final buyer, used to verify legal origin.'],
            ['slug' => 'moq', 'term' => 'MOQ', 'definition' => 'Minimum Order Quantity — the smallest volume a supplier will accept for a given order.'],
            ['slug' => 'grade', 'term' => 'Grade', 'definition' => 'A classification of timber quality based on visible defects, dimensions and appearance, used to set price and suitability for a given use.'],
            ['slug' => 'board-foot', 'term' => 'Board Foot', 'definition' => 'An imperial unit of lumber volume equal to a board 12 inches by 12 inches by 1 inch thick, still used in some export markets alongside CBM.'],
            ['slug' => 'container-loading', 'term' => 'Container Loading', 'definition' => 'The process of packing timber into a shipping container, optimised for volume, weight distribution and protection from moisture and damage in transit.'],
            ['slug' => 'phytosanitary-certificate', 'term' => 'Phytosanitary Certificate', 'definition' => 'An official document certifying that a timber shipment meets the plant-health import requirements of the destination country.'],
        ];

        foreach ($terms as $term) {
            GlossaryTerm::updateOrCreate(['slug' => $term['slug']], $term + ['is_published' => true]);
        }
    }
}
```

- [ ] **Step 13: Run tests to verify they pass**

```bash
php artisan migrate --force
php artisan db:seed --class=GlossaryTermSeeder --force
php artisan test tests/Feature/GlossaryTest.php
```
Expected: PASS (6 tests).

- [ ] **Step 14: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add -A
git commit -m "Add the Cameroon Timber Glossary: model, public pages, Filament admin, sitemap/llms.txt wiring, 15 core terms"
```

---

### Task 5: Publish the first real Compliance article — EUDR — proving the pipeline end to end

**Files:**
- Create: `content/regulation/eu-deforestation-regulation-eudr-explained.md` (if the existing `content/` importer convention is markdown-with-frontmatter — confirm against `app/Console/Commands/ImportArticles.php` and the existing `content/` sample file first, and match its exact frontmatter schema)
- Test: `tests/Feature/EudrArticlePublishedTest.php`

This task authors one real, accurately sourced article using the existing `Article`/`ArticleBody`/importer pipeline exactly as built in Task 1 — proving the whole platform end to end, not adding new code.

- [ ] **Step 1: Read the existing content convention**

```bash
cat "content/README.md" 2>/dev/null || find content -maxdepth 2 -type f
cat app/Console/Commands/ImportArticles.php
```
Note the exact frontmatter keys the importer expects (title, category, keywords, faqs, sources, etc.) before writing Step 2 — match that schema exactly, since this plan cannot see the file's current content ahead of time.

- [ ] **Step 2: Write the article**

Create `content/regulation/eu-deforestation-regulation-eudr-explained.md` with frontmatter matching the schema found in Step 1, category `regulation`, and a body covering, factually and with real citations:
- What the EU Deforestation Regulation (Regulation (EU) 2023/1115) is and which authority issued it (the European Union).
- That it requires operators placing covered commodities — including timber and wood products — on the EU market to demonstrate the goods are deforestation-free (not produced on land deforested after the regulation's cutoff date) and produced in compliance with the country of production's relevant laws.
- That it requires due diligence including geolocation data and a risk assessment.
- A clear, prominent disclaimer that this is general informational content, not legal advice, and that exporters/buyers must confirm current requirements and applicable dates directly via the official EU source, because implementation and phased application dates are subject to change.
- An `authoritative_sources`/`sources` frontmatter entry citing the official EU source (EUR-Lex) by name and URL, with today's access date.
- A closing section linking to real, resolved internal targets using the existing `[text](species:slug)` / `[text](suppliers:)` / `[text](rfq:)` scheme already built in `ArticleBody` — e.g. linking to the `/wood-species` directory and to `rfq:` — so the article demonstrates the education-to-commerce link the spec requires, per its own established scheme, not new markup.

Do not state specific compliance deadlines, exemption thresholds, or country risk classifications as fixed facts — these are exactly the kind of detail that changes and that the spec's editorial standard (§M) requires be sourced and dated rather than asserted from memory. Where such a detail would strengthen the piece, phrase it as "consult the current EU source for exact dates and thresholds" rather than asserting a number.

- [ ] **Step 3: Import it through the existing pipeline**

```bash
php artisan articles:import
```
Expected: the command reports the new file imported, creating an `Article` row with `category = regulation`, `status = published` (or whatever the importer's default/frontmatter-driven status is — confirm against the command's actual behavior).

- [ ] **Step 4: Write the verification test**

```php
<?php

use App\Models\Article;

it('publishes the EUDR compliance article with real sources and a legal disclaimer', function () {
    $article = Article::published()->where('slug', 'like', 'eu-deforestation-regulation%')->first();

    expect($article)->not->toBeNull();

    $response = $this->get($article->url())
        ->assertOk()
        ->assertSee('Deforestation Regulation')
        ->assertSee('not legal advice', false);

    expect($article->sources)->not->toBeEmpty();
});

it('includes the EUDR article in the sitemap', function () {
    $article = Article::published()->where('slug', 'like', 'eu-deforestation-regulation%')->firstOrFail();

    $this->get(route('sitemap'))->assertOk()->assertSee($article->url(), false);
});
```

(Adjust the slug match if the importer generates a different exact slug — check the actual created row before finalizing this test.)

- [ ] **Step 5: Run to verify it passes**

```bash
php artisan test tests/Feature/EudrArticlePublishedTest.php
```
Expected: PASS (2 tests).

- [ ] **Step 6: Manual read-through**

```bash
php artisan serve --port=8000 &
sleep 2
curl -s http://127.0.0.1:8000/insights/eu-deforestation-regulation-eudr-explained | grep -o "not legal advice\|Deforestation Regulation\|EUR-Lex"
```
Confirm the disclaimer, the regulation name, and the real source citation are all present in the rendered output — this is the one step in this plan that is a human/editorial judgment call, not a code assertion: read the actual published page and confirm it reads as accurate, useful, and appropriately hedged before treating this task as done.

- [ ] **Step 7: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add content/regulation/eu-deforestation-regulation-eudr-explained.md tests/Feature/EudrArticlePublishedTest.php
git commit -m "Publish the first Compliance Academy article: EUDR explained, sourced and disclaimed"
```

---

## Self-Review Notes

- **Spec coverage (§C, §G, §N glossary/llms.txt items, §M editorial discipline):** Task 3 covers §C's field list in full (schema only, as the 30-day scope specifies). Task 2 covers §G's `llms.txt` requirement. Task 4 covers the Glossary system named in the Educational Resources brief and referenced in §B's taxonomy. Task 5 proves §M's editorial standard (sourced, dated, disclaimed, no fabricated deadlines) and §A's internal-linking requirement against one real page. Full 17-hub content, calculators, courses, French IA, Price Index and the 200-row search-intent CSV are explicitly deferred per the plan header — they are 60/90-day/6-month spec items, not P0.
- **Placeholder scan:** every step has complete, runnable code or an explicit "read the existing file first, match its shape" instruction where the target file's current content genuinely cannot be seen from this plan (Task 4 Step 11, Task 5 Step 1) — those are the only two steps without a full code block, and both name exactly what to read and match rather than leaving a "TODO."
- **Type consistency:** `GlossaryTerm::published()`, `->url()`, `relatedTerms()`, `relatedSpecies()` are defined once in Task 4 Step 4 and used identically in the controller (Step 6), views (Step 7) and sitemap wiring (Step 9). `Species::eudr_risk_note` defined in Task 3 Step 3/4 is the exact field name rendered in Step 5 and asserted in the Task 3 test.
