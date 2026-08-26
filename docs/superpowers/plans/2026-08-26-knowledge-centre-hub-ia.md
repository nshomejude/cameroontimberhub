# Knowledge Centre Hub IA — Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `/knowledge/{hub}` pillar-page information architecture from spec §B/§E, and move the existing evergreen articles onto their final URLs — so the ~20 articles of Phase 3 are written at URLs that never have to move.

**Architecture:** A code-defined `KnowledgeHub` enum (11 hubs, each with slug/label/description) is the single source of truth. `Article` gains a nullable `hub` column: an article **with** a hub is evergreen Knowledge Centre content served at `/knowledge/{hub}/{slug}`; an article **without** one stays short-form news at `/insights/{slug}`, exactly as spec §B distinguishes them. Pillar page bodies are optional markdown files at `content/knowledge/{hub}.md`, so a hub renders its child listing honestly even before pillar copy is written — no fabricated placeholder prose. The existing `SlugRedirect` model carries `/insights/{slug}` → `/knowledge/{hub}/{slug}` for anything that moves.

**Tech Stack:** Laravel 13, Filament 5, PostgreSQL (FTS + trigram), Pest.

Spec: `docs/superpowers/specs/2026-08-26-seo-ai-authority-architecture.md` (§B taxonomy, §E cluster/pillar model, §G AI authority, §H structured data)

Phase 1 (committed, deployed): `docs/superpowers/plans/2026-08-26-seo-authority-phase1.md`

**Critical environment note for every task:** the test suite shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the **foreground only, one run at a time** — never in the background, never concurrently. If you see "relation X already exists" or "relation migrations does not exist", that is corruption from a concurrent run: repair with `php artisan migrate:fresh --env=testing --force`, then re-run once. Baseline at the start of this plan: **649 tests, 649 passed, 2974 assertions**.

**Standing conventions this codebase enforces** (violating any of these is a defect, not a style choice):
- **Never fabricate data.** No placeholder prose, no invented counts, no guessed regulatory specifics. A missing value renders as an honest absence or the element is hidden.
- **One `published()`-style scope per model** as the single visibility gate, read through by index, show, search, sitemap and llms.txt alike.
- Regulation-category articles must carry a "not legal advice" disclaimer as their first body element and access-dated sources — enforced by `ArticleForm` validation and `EudrArticlePublishedTest`.
- JSON-LD goes through the layout's `:schema` prop. Never emit a manual `<script type="application/ld+json">` tag in a view.
- A Filament `Repeater` with required subfields needs `->defaultItems(0)` or it blocks record creation.
- Postgres jsonb does not preserve object key order — assert with `toEqual`, not `toBe`.

**Explicitly out of scope for this plan:**
- Writing the ~20 articles themselves — that is Phase 3, and this plan exists so that work lands at final URLs.
- `/knowledge/courses`, `/knowledge/case-studies`, `/tools/*` calculators, `/market/*` Data Centre, the French `/fr` tree — all later-phase items in spec §O.
- Renaming `/species` to the spec's `/wood-species`. The spec's own §B reasoning against breaking established URLs for zero SEO gain applies here; the existing slug is already keyword-strong and carries indexed inbound links.
- Expanding the glossary beyond its current 15 terms.

---

### Task 1: The `KnowledgeHub` enum

**Files:**
- Create: `app/Enums/KnowledgeHub.php`
- Test: `tests/Feature/KnowledgeHubTest.php`

Eleven hubs from spec §B, code-defined so routes, navigation, sitemap and the Filament form all read one source.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\KnowledgeHub;

it('defines every hub from the spec taxonomy with a stable slug', function () {
    $slugs = array_map(fn (KnowledgeHub $h) => $h->value, KnowledgeHub::cases());

    expect($slugs)->toEqual([
        'fundamentals',
        'cameroon-101',
        'products',
        'processing',
        'grading',
        'buying',
        'export',
        'compliance',
        'sustainability',
        'logistics',
        'business',
    ]);
});

it('gives every hub a label and a description with no placeholder text', function () {
    foreach (KnowledgeHub::cases() as $hub) {
        expect($hub->label())->not->toBeEmpty()
            ->and($hub->description())->not->toBeEmpty()
            ->and(strtolower($hub->description()))->not->toContain('lorem')
            ->and(strtolower($hub->description()))->not->toContain('tbd')
            ->and(strtolower($hub->description()))->not->toContain('placeholder');
    }
});

it('resolves a hub from its slug and rejects an unknown one', function () {
    expect(KnowledgeHub::tryFrom('compliance'))->toBe(KnowledgeHub::Compliance)
        ->and(KnowledgeHub::tryFrom('not-a-hub'))->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/KnowledgeHubTest.php`
Expected: FAIL — `Class "App\Enums\KnowledgeHub" not found`.

- [ ] **Step 3: Write the enum**

Read `app/Enums/ArticleCategory.php` first and match its shape exactly (backed string enum, `label()`/`description()` methods, a `color()` if the sibling has one).

```php
<?php

namespace App\Enums;

/**
 * The eleven Knowledge Centre hubs from the SEO authority spec §B.
 *
 * Each case is a URL segment under /knowledge and a pillar page. This enum is
 * the single source of truth: routes, navigation, the sitemap, llms.txt and
 * the Filament article form all read it, so a hub cannot exist in one surface
 * and be missing from another.
 *
 * Distinct from ArticleCategory, which describes what a piece of writing IS
 * (guide, market note, regulation explainer). A hub describes where evergreen
 * content LIVES. An article with no hub is short-form news at /insights.
 */
enum KnowledgeHub: string
{
    case Fundamentals = 'fundamentals';
    case Cameroon101 = 'cameroon-101';
    case Products = 'products';
    case Processing = 'processing';
    case Grading = 'grading';
    case Buying = 'buying';
    case Export = 'export';
    case Compliance = 'compliance';
    case Sustainability = 'sustainability';
    case Logistics = 'logistics';
    case Business = 'business';

    public function label(): string
    {
        return match ($this) {
            self::Fundamentals => 'Timber Fundamentals',
            self::Cameroon101 => 'Cameroon Timber 101',
            self::Products => 'Products Academy',
            self::Processing => 'Processing Academy',
            self::Grading => 'Quality & Grading Academy',
            self::Buying => 'Buyer Academy',
            self::Export => 'Export Academy',
            self::Compliance => 'Compliance Academy',
            self::Sustainability => 'Sustainability Academy',
            self::Logistics => 'Logistics Academy',
            self::Business => 'Business Academy',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Fundamentals => 'How timber behaves as a material — species groups, density, moisture, movement and durability.',
            self::Cameroon101 => 'Cameroon as a timber origin: producing regions, forest types, the production chain, ports and institutions.',
            self::Products => 'The traded forms of Cameroonian timber — logs, sawn timber, boules, veneer, plywood and finished components.',
            self::Processing => 'What happens between forest and container: sawmilling, drying, treatment and secondary processing.',
            self::Grading => 'How Cameroonian timber is graded and specified, and what a grade actually guarantees a buyer.',
            self::Buying => 'Sourcing Cameroonian timber: specifying an order, verifying a supplier, Incoterms, payment and inspection.',
            self::Export => 'Moving timber out of Cameroon — documentation, customs, phytosanitary requirements, ports and containerisation.',
            self::Compliance => 'The legal frameworks that govern Cameroonian timber: EUDR, FLEGT, CITES, SIGIF II and chain of custody.',
            self::Sustainability => 'Certification, legal sourcing, forest management and what sustainability claims can and cannot assert.',
            self::Logistics => 'Freight, routing, packing, insurance and the practical mechanics of shipping timber internationally.',
            self::Business => 'Contracts, pricing, risk, financing and the commercial mechanics of the timber trade.',
        };
    }

    public function url(): string
    {
        return route('knowledge.hub', $this->value);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/KnowledgeHubTest.php`
Expected: PASS (3 tests). Note `url()` is not exercised until Task 3 registers the route — that is fine, no test here calls it.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Enums/KnowledgeHub.php tests/Feature/KnowledgeHubTest.php
git commit -m "Define the eleven Knowledge Centre hubs from the SEO authority spec"
```

---

### Task 2: Give `Article` a hub

**Files:**
- Create: `database/migrations/2026_08_28_100010_add_hub_to_articles_table.php`
- Modify: `app/Models/Article.php`
- Modify: `database/factories/ArticleFactory.php`
- Modify: `app/Filament/Resources/Articles/Schemas/ArticleForm.php`
- Test: `tests/Feature/ArticleHubTest.php`

Nullable by design: null means short-form news at `/insights/{slug}`, a hub means evergreen content at `/knowledge/{hub}/{slug}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\KnowledgeHub;
use App\Models\Article;

it('stores a hub and casts it to the enum', function () {
    $article = Article::factory()->create(['hub' => KnowledgeHub::Compliance]);

    expect($article->fresh()->hub)->toBe(KnowledgeHub::Compliance);
});

it('leaves hub null by default, marking an article as short-form news', function () {
    expect(Article::factory()->create()->fresh()->hub)->toBeNull();
});

it('routes an article to its hub URL when it has one and to insights when it does not', function () {
    $evergreen = Article::factory()->create(['slug' => 'hub-url-test', 'hub' => KnowledgeHub::Export]);
    $news = Article::factory()->create(['slug' => 'news-url-test', 'hub' => null]);

    expect($evergreen->url())->toBe(route('knowledge.article', ['hub' => 'export', 'slug' => 'hub-url-test']))
        ->and($news->url())->toBe(route('insights.show', 'news-url-test'));
});

it('scopes articles to a hub', function () {
    Article::factory()->create(['slug' => 'in-hub', 'hub' => KnowledgeHub::Buying]);
    Article::factory()->create(['slug' => 'other-hub', 'hub' => KnowledgeHub::Export]);
    Article::factory()->create(['slug' => 'no-hub', 'hub' => null]);

    $slugs = Article::published()->inHub(KnowledgeHub::Buying)->pluck('slug')->all();

    expect($slugs)->toBe(['in-hub']);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/ArticleHubTest.php`
Expected: FAIL — unknown column `hub`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge Centre hub assignment — spec §B.
 *
 * Nullable on purpose and with no default. A null hub is not missing data: it
 * marks the article as short-form news served at /insights/{slug}, which spec
 * §B keeps deliberately distinct from evergreen /knowledge content. Nothing is
 * backfilled here; Task 4 moves the two existing evergreen articles explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('hub', 40)->nullable()->after('category');
            $table->index(['hub', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['hub', 'published_at']);
            $table->dropColumn('hub');
        });
    }
};
```

Verify `category` and `published_at` genuinely exist on `articles` before finalizing (Postgres ignores `after()`, so it is cosmetic, but the index must reference real columns).

- [ ] **Step 4: Update the model**

In `app/Models/Article.php`:

Add to the `casts()` array:
```php
            'hub' => KnowledgeHub::class,
```

Add the `use App\Enums\KnowledgeHub;` import.

Add the scope, next to the existing `published()`:
```php
    public function scopeInHub(Builder $query, KnowledgeHub $hub): Builder
    {
        return $query->where('hub', $hub->value);
    }
```

Then find the existing `url()` method and change it so a hubbed article resolves to its Knowledge Centre URL:
```php
    public function url(): string
    {
        return $this->hub
            ? route('knowledge.article', ['hub' => $this->hub->value, 'slug' => $this->slug])
            : route('insights.show', $this->slug);
    }
```

**This is the highest-risk edit in the plan.** `url()` is already consumed by the article card component, the insights index and show views, `SitemapController::index()`, `SitemapController::llms()`, and `EudrArticlePublishedTest`. Changing it is intentional — every one of those surfaces should follow an article to its real home — but read each call site before committing and confirm none breaks.

- [ ] **Step 5: Update the factory**

In `database/factories/ArticleFactory.php`, add `'hub' => null,` to `definition()` (explicit, so the default is visible rather than incidental), and add a state:
```php
    public function inHub(KnowledgeHub $hub): static
    {
        return $this->state(fn () => ['hub' => $hub]);
    }
```

- [ ] **Step 6: Add the hub selector to the Filament form**

In `app/Filament/Resources/Articles/Schemas/ArticleForm.php`, beside the existing `category` select:
```php
Select::make('hub')
    ->label('Knowledge Centre hub')
    ->options(collect(KnowledgeHub::cases())->mapWithKeys(fn (KnowledgeHub $h) => [$h->value => $h->label()]))
    ->searchable()
    ->helperText('Leave blank for short-form news, which stays at /insights. Choosing a hub publishes this as evergreen Knowledge Centre content at /knowledge/{hub}/{slug}.'),
```

Add the `use App\Enums\KnowledgeHub;` import. Match the surrounding form's actual component and layout idiom.

- [ ] **Step 7: Run to verify it passes**

```bash
php artisan migrate --force
php artisan test tests/Feature/ArticleHubTest.php
```
Expected: PASS (4 tests). The URL tests depend on routes registered in Task 3 — if you are running tasks strictly in order, expect a route-not-defined failure here and either land Task 3's routes first or split those two assertions into Task 3. Note which you did in your report.

- [ ] **Step 8: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add -A
git commit -m "Give articles a Knowledge Centre hub, keeping unhubbed articles as /insights news"
```

---

### Task 3: Routes, controller and views for `/knowledge`

**Files:**
- Create: `app/Http/Controllers/Public/KnowledgeController.php`
- Create: `resources/views/public/knowledge/index.blade.php`
- Create: `resources/views/public/knowledge/hub.blade.php`
- Create: `content/knowledge/README.md`
- Modify: `routes/web.php`
- Modify: `resources/views/components/layouts/app.blade.php` (nav + footer)
- Test: `tests/Feature/KnowledgeCentreTest.php`

Three routes: the Knowledge Centre landing, a hub pillar page, and an article within a hub. **Route ordering matters** — `/knowledge/glossary` already exists and must keep winning over `/knowledge/{hub}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Enums\KnowledgeHub;
use App\Models\Article;

it('lists every hub on the Knowledge Centre landing page', function () {
    $response = $this->get(route('knowledge.index'))->assertOk();

    foreach (KnowledgeHub::cases() as $hub) {
        $response->assertSee($hub->label(), false);
    }
});

it('serves a hub pillar page listing only that hub published articles', function () {
    Article::factory()->create([
        'title' => 'Mine In This Hub', 'slug' => 'in-this-hub', 'hub' => KnowledgeHub::Export,
    ]);
    Article::factory()->create([
        'title' => 'Some Other Hub', 'slug' => 'other-hub-piece', 'hub' => KnowledgeHub::Buying,
    ]);
    Article::factory()->create([
        'title' => 'Just The News', 'slug' => 'plain-news', 'hub' => null,
    ]);

    $this->get(route('knowledge.hub', 'export'))
        ->assertOk()
        ->assertSee(KnowledgeHub::Export->label(), false)
        ->assertSee('Mine In This Hub')
        ->assertDontSee('Some Other Hub')
        ->assertDontSee('Just The News');
});

it('serves an article at its hub URL', function () {
    Article::factory()->create([
        'title' => 'Phytosanitary Requirements', 'slug' => 'phytosanitary-requirements', 'hub' => KnowledgeHub::Export,
    ]);

    $this->get(route('knowledge.article', ['hub' => 'export', 'slug' => 'phytosanitary-requirements']))
        ->assertOk()
        ->assertSee('Phytosanitary Requirements');
});

it('404s an article requested under the wrong hub', function () {
    Article::factory()->create(['slug' => 'wrong-hub-test', 'hub' => KnowledgeHub::Export]);

    $this->get(route('knowledge.article', ['hub' => 'buying', 'slug' => 'wrong-hub-test']))->assertNotFound();
});

it('404s an unknown hub and never leaks an unpublished article', function () {
    Article::factory()->create([
        'slug' => 'draft-in-hub', 'hub' => KnowledgeHub::Export, 'status' => ArticleStatus::Draft, 'published_at' => null,
    ]);

    $this->get('/knowledge/not-a-real-hub')->assertNotFound();
    $this->get(route('knowledge.article', ['hub' => 'export', 'slug' => 'draft-in-hub']))->assertNotFound();
    $this->get(route('knowledge.hub', 'export'))->assertOk()->assertDontSee('draft-in-hub');
});

it('keeps the glossary route winning over the hub route', function () {
    $this->get('/knowledge/glossary')->assertOk()->assertSee('Glossary', false);
});

it('emits CollectionPage schema on a hub page', function () {
    Article::factory()->create(['slug' => 'schema-hub-test', 'hub' => KnowledgeHub::Compliance]);

    $this->get(route('knowledge.hub', 'compliance'))
        ->assertOk()
        ->assertSee('CollectionPage', false)
        ->assertSee('ItemList', false);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/KnowledgeCentreTest.php`
Expected: FAIL — route `knowledge.index` not defined.

- [ ] **Step 3: Write the controller**

Read `app/Http/Controllers/Public/GlossaryController.php` and `InsightController.php` first — match their shape (constructor-less, view-data arrays, breadcrumbs, a private schema builder, single `published()` gate).

```php
<?php

namespace App\Http\Controllers\Public;

use App\Enums\KnowledgeHub;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Support\ArticleBody;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

/**
 * The Knowledge Centre — /knowledge and its eleven hubs (spec §B, §E).
 *
 * A hub is a pillar page: optional authored intro copy from
 * content/knowledge/{hub}.md, plus the published articles assigned to it. When
 * no pillar file exists the page renders its listing and description only —
 * it never invents placeholder pillar prose.
 */
class KnowledgeController extends Controller
{
    public function index(): View
    {
        $counts = Article::published()
            ->whereNotNull('hub')
            ->selectRaw('hub, count(*) as total')
            ->groupBy('hub')
            ->pluck('total', 'hub');

        return view('public.knowledge.index', [
            'hubs' => KnowledgeHub::cases(),
            'counts' => $counts,
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Knowledge Centre', 'url' => route('knowledge.index')],
            ],
        ]);
    }

    public function hub(string $hub): View
    {
        $knowledgeHub = KnowledgeHub::tryFrom($hub) ?? abort(404);

        $articles = Article::published()
            ->inHub($knowledgeHub)
            ->orderByDesc('published_at')
            ->get();

        return view('public.knowledge.hub', [
            'hub' => $knowledgeHub,
            'articles' => $articles,
            'pillar' => $this->pillarBody($knowledgeHub),
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Knowledge Centre', 'url' => route('knowledge.index')],
                ['label' => $knowledgeHub->label(), 'url' => $knowledgeHub->url()],
            ],
            'schema' => $this->hubSchema($knowledgeHub, $articles),
        ]);
    }

    /**
     * Rendered pillar copy for a hub, or null when none has been written yet.
     */
    private function pillarBody(KnowledgeHub $hub): ?string
    {
        $path = base_path("content/knowledge/{$hub->value}.md");

        return File::exists($path) ? ArticleBody::render(File::get($path)) : null;
    }

    /** @return array<string, mixed> */
    private function hubSchema(KnowledgeHub $hub, iterable $articles): array
    {
        $items = [];

        foreach ($articles as $position => $article) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position + 1,
                'url' => $article->url(),
                'name' => $article->title,
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            '@id' => $hub->url().'#hub',
            'name' => $hub->label(),
            'description' => $hub->description(),
            'url' => $hub->url(),
            'mainEntity' => [
                '@type' => 'ItemList',
                'numberOfItems' => count($items),
                'itemListElement' => $items,
            ],
        ];
    }
}
```

**Confirm `ArticleBody::render()` is genuinely a static method with that signature** before using it this way — read the class. If it is not static, resolve it from the container instead.

- [ ] **Step 4: Add the article action to `InsightController`, not a second controller**

The article *show* view already exists and works. Rather than duplicate it, add a hub-aware action to `app/Http/Controllers/Public/InsightController.php` that reuses the same view and schema builder:

```php
    /**
     * An evergreen article at its Knowledge Centre URL. The hub segment is part
     * of the article's identity here: requesting a real article under the wrong
     * hub is a 404, so exactly one canonical URL resolves per piece.
     */
    public function hubArticle(string $hub, string $slug): View
    {
        $knowledgeHub = KnowledgeHub::tryFrom($hub) ?? abort(404);

        $article = Article::published()
            ->inHub($knowledgeHub)
            ->where('slug', $slug)
            ->firstOrFail();

        return $this->renderArticle($article);
    }
```

Read the existing `show()` method first. If its body is inline rather than delegating, extract the shared part into a private `renderArticle(Article $article): View` and have both `show()` and `hubArticle()` call it — do not copy-paste the body. Add the `use App\Enums\KnowledgeHub;` import.

- [ ] **Step 5: Register the routes**

In `routes/web.php`, **after** the two existing glossary routes so the static `glossary` segment always wins:

```php
Route::get('/knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
Route::get('/knowledge/{hub}', [KnowledgeController::class, 'hub'])
    ->where('hub', '[a-z0-9][a-z0-9-]*')->name('knowledge.hub');
Route::get('/knowledge/{hub}/{slug}', [InsightController::class, 'hubArticle'])
    ->where(['hub' => '[a-z0-9][a-z0-9-]*', 'slug' => '[a-z0-9][a-z0-9-]*'])
    ->name('knowledge.article');
```

Add the `use App\Http\Controllers\Public\KnowledgeController;` import. **Verify the glossary routes are declared above these** — the test "keeps the glossary route winning over the hub route" exists precisely to catch getting this backwards.

- [ ] **Step 6: Write the two views**

Follow `resources/views/public/glossary/index.blade.php` exactly for the shell, breadcrumb block, and token usage (`text-ink`, `text-ink-soft`, `sand-300`, `forest-700`, the `eyebrow` class, the `bg-white` + `mx-auto max-w-* px-4 py-8 lg:px-6` wrapper). Pass schema via the `:schema` prop — no manual `<script>` tag.

`resources/views/public/knowledge/index.blade.php` renders each hub as a card: label, description, and the article count from `$counts` **only when greater than zero** (an empty hub shows no "0 articles" label — hide, don't show a zero).

`resources/views/public/knowledge/hub.blade.php` renders the hub label and description, then `{!! $pillar !!}` inside `@if ($pillar)` (safe: `ArticleBody::render()` escapes HTML input), then the article list reusing the existing `<x-article-card>` component. When `$articles` is empty, render the same dashed-border empty state the glossary index uses — do not fabricate "coming soon" copy beyond a plain statement that nothing is published in this hub yet.

- [ ] **Step 7: Document the pillar-file convention**

Create `content/knowledge/README.md` explaining: one optional `{hub-slug}.md` per hub, plain markdown with no frontmatter, rendered through `ArticleBody` so the `species:`/`marketplace:`/`suppliers:`/`rfq:`/`insights:` link schemes work; absent file means the hub renders description + listing only.

- [ ] **Step 8: Add Knowledge Centre to nav and footer**

In `resources/views/components/layouts/app.blade.php`, add a Knowledge Centre entry to the same nav and footer arrays the Glossary was added to in Phase 1. Read how Glossary was wired and match it.

- [ ] **Step 9: Run to verify it passes**

Run: `php artisan test tests/Feature/KnowledgeCentreTest.php`
Expected: PASS (7 tests).

- [ ] **Step 10: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add -A
git commit -m "Build the Knowledge Centre: landing page, eleven hub pillars and hub article URLs"
```

---

### Task 4: Move the two evergreen articles, with redirects

**Files:**
- Modify: `content/articles/eu-deforestation-regulation-eudr-explained.md`
- Modify: `content/articles/eudr-compliance-cameroon-timber.md`
- Modify: `app/Console/Commands/ImportArticles.php`
- Test: `tests/Feature/ArticleHubRedirectTest.php`

Both existing articles are evergreen compliance content. They move to `/knowledge/compliance/{slug}`, and `/insights/{slug}` must keep resolving via `SlugRedirect`.

- [ ] **Step 1: Read the existing redirect machinery first**

```bash
cat app/Models/SlugRedirect.php
grep -rn "SlugRedirect" app/ routes/ --include=*.php
```
Learn how redirects are stored and served (which columns, which middleware or controller consumes them, what status code they issue) before writing anything. The plan cannot see this file; match what is actually there.

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Enums\KnowledgeHub;
use App\Models\Article;

it('serves both EUDR articles under the compliance hub', function () {
    $this->artisan('articles:import');

    foreach (['eu-deforestation-regulation-eudr-explained', 'eudr-compliance-cameroon-timber'] as $slug) {
        $article = Article::published()->where('slug', $slug)->first();

        expect($article)->not->toBeNull()
            ->and($article->hub)->toBe(KnowledgeHub::Compliance);

        $this->get(route('knowledge.article', ['hub' => 'compliance', 'slug' => $slug]))->assertOk();
    }
});

it('redirects the old insights URL to the hub URL', function () {
    $this->artisan('articles:import');

    $this->get('/insights/eu-deforestation-regulation-eudr-explained')
        ->assertRedirect(route('knowledge.article', [
            'hub' => 'compliance', 'slug' => 'eu-deforestation-regulation-eudr-explained',
        ]));
});

it('lists both articles on the compliance hub page', function () {
    $this->artisan('articles:import');

    $this->get(route('knowledge.hub', 'compliance'))
        ->assertOk()
        ->assertSee('EU Deforestation Regulation', false);
});
```

Adjust the redirect assertion to whatever status and mechanism Step 1 revealed — if redirects are served by middleware on a 404 rather than a route, assert accordingly.

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test tests/Feature/ArticleHubRedirectTest.php`
Expected: FAIL — `hub` is null on both articles.

- [ ] **Step 4: Teach the importer about `hub`**

In `app/Console/Commands/ImportArticles.php`, accept a `hub` frontmatter key, validate it against `KnowledgeHub::tryFrom()`, and fail the import for that file with a clear message if the value is not a real hub — do not silently import with a null hub, which would put the article at the wrong URL. Read how the command currently validates `category` and `status` and follow that exact pattern.

- [ ] **Step 5: Add `hub: compliance` to both article files**

Add the key to the frontmatter of both markdown files, beside the existing `category: regulation`.

- [ ] **Step 6: Create the redirects**

Whichever mechanism Step 1 revealed, create `SlugRedirect` rows mapping each old `/insights/{slug}` path to the new hub URL. Prefer doing this **in the importer** — when an article's hub changes and it previously had a different URL, record the redirect automatically — so Phase 3's articles get the same protection without anyone remembering. If that proves to need a design decision you cannot make confidently, create the two rows in a small seeder instead and say so in your report.

- [ ] **Step 7: Run to verify it passes**

```bash
php artisan articles:import
php artisan test tests/Feature/ArticleHubRedirectTest.php
```
Expected: PASS (3 tests).

- [ ] **Step 8: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add -A
git commit -m "Move the EUDR articles into the compliance hub, redirecting their old insights URLs"
```

---

### Task 5: Sitemap, llms.txt and canonical consistency

**Files:**
- Modify: `app/Http/Controllers/Public/SitemapController.php`
- Test: `tests/Feature/ArticleTest.php` (extend the existing "Sitemap, robots and llms.txt" section)

`/knowledge` and its eleven hubs are landing pages and belong in `keyPages()`; hub articles must appear at their real URLs in both outputs, not their old ones.

- [ ] **Step 1: Write the failing test**

Add to the existing sitemap/llms section in `tests/Feature/ArticleTest.php`:

```php
it('lists the Knowledge Centre and every hub in the sitemap and llms.txt', function () {
    $sitemap = $this->get(route('sitemap'))->assertOk();
    $llms = $this->get('/llms.txt')->assertOk();

    $sitemap->assertSee(route('knowledge.index'), false);
    $llms->assertSee(route('knowledge.index'), false);

    foreach (App\Enums\KnowledgeHub::cases() as $hub) {
        $sitemap->assertSee($hub->url(), false);
        $llms->assertSee($hub->url(), false);
    }
});

it('lists a hub article at its knowledge URL, not its old insights URL', function () {
    $article = App\Models\Article::factory()->create([
        'slug' => 'sitemap-hub-test', 'hub' => App\Enums\KnowledgeHub::Grading,
    ]);

    $this->get(route('sitemap'))->assertOk()
        ->assertSee($article->url(), false)
        ->assertDontSee(route('insights.show', 'sitemap-hub-test'), false);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/ArticleTest.php`
Expected: FAIL — knowledge URLs absent from both outputs.

- [ ] **Step 3: Add the hubs to `keyPages()`**

`keyPages()` is the shared source of truth both `index()` (XML) and `llms()` (text) consume — added in Phase 1 precisely so the two cannot drift. Add `/knowledge` and, by looping `KnowledgeHub::cases()`, all eleven hub pages, each with a label and priority consistent with the existing rows. Read the method and match its row shape exactly.

- [ ] **Step 4: Fix the three known stale-URL surfaces**

Task 2's review established exactly where an article's URL is built *without* going through the now-hub-aware `Article::url()`. All three must be fixed here:

1. **`SitemapController::index()`** — hand-builds `route('insights.show', $article->slug)` rather than calling `url()`, so it emits a stale `/insights/` loc for a hubbed article. Change it to `$article->url()`, and add `hub` to that query's column scope (it selects `['slug','updated_at','published_at']`; a partially-hydrated model **throws** on an unselected attribute).

2. **`SitemapController::llms()`** — already fixed in Task 2 (its select carries `hub`). **Verify, don't re-fix.**

3. **`app/Support/ArticleBody.php`** — the `insights:{slug}` markdown link scheme resolves straight to `route('insights.show', $value)`, so an in-body cross-link to an article that later moves into a hub points at the old URL. The `SlugRedirect` absorbs it as a 301 rather than a 404, so this is a quality issue not a breakage — but resolve the slug to its `Article` and use `url()` so in-body links land on the canonical URL directly. If the article does not exist, keep the current fallback behaviour rather than erroring.

Also confirm `resources/views/public/insights/show.blade.php`'s `<link rel="canonical">` (which calls `url()`) is correct for a hubbed article reached via redirect — it should point at the hub URL, which is the whole intent.

While here, fix the now-half-true class docblock on `app/Models/Article.php` ("An editorial article on /insights") to describe both homes.

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test tests/Feature/ArticleTest.php`
Expected: PASS.

- [ ] **Step 6: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add -A
git commit -m "Publish the Knowledge Centre hubs through the sitemap and llms.txt"
```

---

## Self-Review Notes

- **Spec coverage:** §B's `/knowledge` taxonomy is realised for all eleven article hubs (Tasks 1, 3); §E's pillar-page-plus-supporting-pages model is the hub controller's shape, with pillar copy optional so nothing is fabricated (Task 3); §G's crawlability and one-canonical-URL-per-entity are served by the wrong-hub 404 and the redirect (Tasks 3, 4); §H's structured data by the hub `CollectionPage`/`ItemList` (Task 3). `/knowledge/courses`, `/knowledge/case-studies`, `/tools/*` and `/market/*` are deferred per the plan header — all later-phase items in §O.
- **Placeholder scan:** every step has runnable code or an explicit "read the real file first and match it" instruction where the target's current contents genuinely cannot be seen from here (Task 3 Steps 4/6/8, Task 4 Steps 1/4/6, Task 5 Steps 3/4). Those name exactly what to read and what to match rather than leaving a TODO.
- **Type consistency:** `KnowledgeHub::url()` (Task 1) is used by the controller, schema builder, breadcrumbs (Task 3) and sitemap (Task 5). `Article::inHub()` and the hub-aware `Article::url()` (Task 2) are consumed by the controller (Task 3), the redirect test (Task 4) and both sitemap loops (Task 5). Route names `knowledge.index`, `knowledge.hub`, `knowledge.article` are fixed in Task 3 Step 5 and used identically everywhere after.
- **Known ordering hazard:** Task 2's `url()` tests reference routes that Task 3 registers. Task 2 Step 7 flags this explicitly and offers two resolutions rather than leaving the implementer to discover it mid-run.
- **Highest-risk change:** rewriting `Article::url()` (Task 2 Step 4), because six existing call sites consume it. Called out inline at the point of the edit rather than only here.
