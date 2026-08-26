<?php

use App\Enums\ArticleCategory;
use App\Enums\KnowledgeHub;
use App\Models\Article;
use App\Models\Species;
use App\Support\ArticleBody;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| /insights — the public editorial surface
|--------------------------------------------------------------------------
*/

it('renders the insights index', function () {
    Article::factory()->create(['title' => 'Grading Ayous for European buyers']);

    $this->get(route('insights.index'))
        ->assertOk()
        ->assertSee('Timber Insights')
        ->assertSee('Grading Ayous for European buyers');
});

it('filters the index by category', function () {
    Article::factory()->category(ArticleCategory::Market)->create(['title' => 'Marketpiece']);
    Article::factory()->category(ArticleCategory::Export)->create(['title' => 'Exportpiece']);

    $this->get(route('insights.category', 'market'))
        ->assertOk()
        ->assertSee('Marketpiece')
        ->assertDontSee('Exportpiece');
});

it('404s an unknown category', function () {
    $this->get(route('insights.category', 'not-a-category'))->assertNotFound();
});

it('paginates the index', function () {
    Article::factory()->count(11)->create();

    $this->get(route('insights.index'))
        ->assertOk()
        ->assertViewHas('articles', fn ($p) => $p->total() === 11 && $p->count() === 9);

    $this->get(route('insights.index', ['page' => 2]))
        ->assertOk()
        ->assertViewHas('articles', fn ($p) => $p->count() === 2);
});

it('searches articles by title', function () {
    Article::factory()->create(['title' => 'Kiln drying schedules', 'excerpt' => 'Drying.']);
    Article::factory()->create(['title' => 'Port congestion in Douala', 'excerpt' => 'Freight.']);

    $this->get(route('insights.index', ['q' => 'Kiln']))
        ->assertOk()
        ->assertSee('Kiln drying schedules')
        ->assertDontSee('Port congestion in Douala');
});

it('renders an article page with body, table of contents, faqs and sources', function () {
    $article = Article::factory()->withFaqs()->withSources()->create([
        'title' => 'Reading a Cameroonian packing list',
        'body' => "## First section\n\nBody paragraph one.\n\n## Second section\n\nBody paragraph two.",
    ]);

    $response = $this->get($article->url())->assertOk();

    $response->assertSee('Reading a Cameroonian packing list');
    $response->assertSee('Body paragraph one.');

    // Table of contents: both H2s, anchored, with jump links.
    $response->assertSee('On this page');
    $response->assertSee('href="#first-section"', false);
    $response->assertSee('href="#second-section"', false);
    $response->assertSee('<h2 id="second-section">', false);

    // FAQ block and citations block.
    $response->assertSee('Frequently asked questions');
    $response->assertSee('Is Cameroon timber legal to import into the EU?');
    $response->assertSee('Sources &amp; further reading', false);
    $response->assertSee('International Tropical Timber Organization');
});

it('renders related articles from the same category, excluding itself', function () {
    $article = Article::factory()->category(ArticleCategory::Buying)->create(['title' => 'Selfpiece']);
    Article::factory()->category(ArticleCategory::Buying)->create(['title' => 'Siblingpiece']);

    $response = $this->get($article->url())->assertOk();

    $response->assertSee('Related reading');
    $response->assertSee('Siblingpiece');
    $response->assertViewHas('related', fn ($r) => ! $r->contains('id', $article->id));
});

it('renders catalogue links in the body as real internal links', function () {
    $species = Species::factory()->create(['slug' => 'sapele', 'common_name' => 'Sapele']);

    $article = Article::factory()->create([
        'body' => "## Species\n\nWe like [Sapele](species:sapele) and [an RFQ](rfq:).",
        'related_species_ids' => [$species->id],
    ]);

    $this->get($article->url())
        ->assertOk()
        ->assertSee('href="'.route('species.show', 'sapele').'"', false)
        ->assertSee('href="'.route('rfq.create').'"', false)
        ->assertSee('Species covered here');
});

it('resolves an in-body insights link to the target article canonical url', function () {
    $hubbed = Article::factory()->create([
        'slug' => 'body-link-hubbed', 'hub' => KnowledgeHub::Compliance,
    ]);
    Article::factory()->create(['slug' => 'body-link-news', 'hub' => null]);

    $article = Article::factory()->create([
        'body' => "## Reading\n\n[Hubbed](insights:body-link-hubbed), "
            .'[News](insights:body-link-news), [Gone](insights:body-link-missing), [All](insights:).',
    ]);

    $this->get($article->url())
        ->assertOk()
        ->assertSee('href="'.$hubbed->url().'"', false)
        ->assertSee('href="'.route('insights.show', 'body-link-news').'"', false)
        // An unknown slug keeps the old fallback rather than erroring.
        ->assertSee('href="'.route('insights.show', 'body-link-missing').'"', false)
        ->assertSee('href="'.route('insights.index').'"', false)
        ->assertDontSee('href="'.route('insights.show', 'body-link-hubbed').'"', false);
});

it('resolves many in-body insights links without an n+1 query', function () {
    foreach (['n1-one', 'n1-two', 'n1-three', 'n1-four'] as $slug) {
        Article::factory()->create(['slug' => $slug, 'hub' => KnowledgeHub::Buying]);
    }

    $markdown = '[a](insights:n1-one) [b](insights:n1-two) [c](insights:n1-three) [d](insights:n1-four)';

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $html = ArticleBody::render($markdown);

    expect($queries)->toBe(1)
        ->and($html)->toContain(KnowledgeHub::Buying->url().'/n1-one');
});

it('escapes raw HTML in an article body', function () {
    $article = Article::factory()->create([
        'body' => "## Heading\n\n<script>alert('xss')</script>",
    ]);

    $this->get($article->url())
        ->assertOk()
        ->assertDontSee('<script>alert', false);
});

/*
|--------------------------------------------------------------------------
| Visibility boundary — drafts and archived articles are never public
|--------------------------------------------------------------------------
*/

it('never shows a draft article publicly', function () {
    $draft = Article::factory()->draft()->create(['title' => 'Draftpiece']);

    $this->get(route('insights.index'))->assertOk()->assertDontSee('Draftpiece');
    $this->get(route('insights.show', $draft->slug))->assertNotFound();
});

it('never shows an archived article publicly', function () {
    $archived = Article::factory()->archived()->create(['title' => 'Archivedpiece']);

    $this->get(route('insights.index'))->assertOk()->assertDontSee('Archivedpiece');
    $this->get(route('insights.show', $archived->slug))->assertNotFound();
});

it('never shows a future-dated article before its publication moment', function () {
    $scheduled = Article::factory()->scheduled()->create(['title' => 'Scheduledpiece']);

    $this->get(route('insights.index'))->assertOk()->assertDontSee('Scheduledpiece');
    $this->get(route('insights.show', $scheduled->slug))->assertNotFound();
});

it('excludes drafts from the category counts', function () {
    Article::factory()->category(ArticleCategory::Guides)->create();
    Article::factory()->draft()->category(ArticleCategory::Guides)->create();

    $this->get(route('insights.index'))
        ->assertOk()
        ->assertViewHas('counts', fn ($counts) => (int) $counts['guides'] === 1);
});

/*
|--------------------------------------------------------------------------
| Structured data
|--------------------------------------------------------------------------
*/

/** Pulls every application/ld+json block out of a rendered page. */
function articleJsonLd(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return array_map(fn (string $json): array => json_decode($json, true) ?? [], $m[1]);
}

it('emits Article and BreadcrumbList structured data on every article', function () {
    $article = Article::factory()->create(['title' => 'Schemapiece']);

    $blocks = articleJsonLd($this->get($article->url())->assertOk()->getContent());

    $types = collect($blocks)->flatMap(fn (array $b) => array_merge(
        [$b['@type'] ?? null],
        collect($b['@graph'] ?? [])->pluck('@type')->all()
    ))->filter()->all();

    expect($types)->toContain('Article')->toContain('BreadcrumbList');

    $articleNode = collect($blocks)
        ->flatMap(fn (array $b) => $b['@graph'] ?? [])
        ->firstWhere('@type', 'Article');

    expect($articleNode['headline'])->toBe('Schemapiece')
        ->and($articleNode['datePublished'])->not->toBeEmpty()
        ->and($articleNode['dateModified'])->not->toBeEmpty()
        ->and($articleNode['mainEntityOfPage']['@id'])->toBe($article->url())
        ->and($articleNode['publisher']['@id'])->toBe(url('/#organization'));
});

it('attributes an article to the organisation when no human author is named', function () {
    $article = Article::factory()->create(['author_name' => null]);

    $node = collect(articleJsonLd($this->get($article->url())->getContent()))
        ->flatMap(fn (array $b) => $b['@graph'] ?? [])
        ->firstWhere('@type', 'Article');

    expect($node['author']['@id'])->toBe(url('/#organization'));
});

it('emits FAQPage only when the article actually renders FAQs', function () {
    $without = Article::factory()->create(['faqs' => []]);
    $with = Article::factory()->withFaqs()->create();

    $typesFor = fn (Article $a): array => collect(articleJsonLd($this->get($a->url())->getContent()))
        ->flatMap(fn (array $b) => array_merge([$b['@type'] ?? null], collect($b['@graph'] ?? [])->pluck('@type')->all()))
        ->filter()->all();

    expect($typesFor($without))->not->toContain('FAQPage');
    expect($typesFor($with))->toContain('FAQPage');

    // And the question emitted as data is the question rendered on the page.
    $this->get($with->url())->assertSee('Is Cameroon timber legal to import into the EU?');
});

it('sets a canonical url on an article page', function () {
    $article = Article::factory()->create();

    $this->get($article->url())
        ->assertOk()
        ->assertSee('<link rel="canonical" href="'.$article->url().'">', false);
});

/*
|--------------------------------------------------------------------------
| Sitemap, robots and llms.txt
|--------------------------------------------------------------------------
*/

it('lists published articles in the sitemap and omits drafts', function () {
    $published = Article::factory()->create(['slug' => 'published-piece']);
    $draft = Article::factory()->draft()->create(['slug' => 'draft-piece']);

    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertSee(route('insights.index'), false)
        ->assertSee(route('marketplace'), false)
        ->assertSee($published->url(), false)
        ->assertDontSee(route('insights.show', $draft->slug), false);
});

it('renders llms.txt listing published articles only', function () {
    $published = Article::factory()->category(ArticleCategory::Market)->create(['title' => 'Llmspiece']);
    Article::factory()->draft()->create(['title' => 'Llmsdraft']);

    $this->get('/llms.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertSee('# '.config('app.name'), false)
        ->assertSee('Market Insights', false)
        ->assertSee(route('marketplace'), false)
        ->assertSee(route('rfq.create'), false)
        ->assertSee('Llmspiece', false)
        ->assertSee($published->url(), false)
        ->assertDontSee('Llmsdraft', false);
});

it('lists published species in llms.txt and omits unpublished ones', function () {
    Species::factory()->create(['common_name' => 'Iroko', 'slug' => 'iroko-llms-test', 'is_published' => true]);
    Species::factory()->create(['common_name' => 'Hidden', 'slug' => 'hidden-llms-test', 'is_published' => false]);

    $this->get('/llms.txt')
        ->assertOk()
        ->assertSee('Species reference', false)
        ->assertSee(route('species.show', 'iroko-llms-test'), false)
        ->assertDontSee('hidden-llms-test', false);
});

it('lists the Knowledge Centre and every hub in the sitemap and llms.txt', function () {
    $sitemap = $this->get(route('sitemap'))->assertOk();
    $llms = $this->get('/llms.txt')->assertOk();

    $sitemap->assertSee(route('knowledge.index'), false);
    $llms->assertSee(route('knowledge.index'), false);

    foreach (KnowledgeHub::cases() as $hub) {
        $sitemap->assertSee($hub->url(), false);
        $llms->assertSee($hub->url(), false);
    }
});

it('lists a hub article at its knowledge URL, not its old insights URL', function () {
    $article = Article::factory()->create([
        'slug' => 'sitemap-hub-test', 'hub' => KnowledgeHub::Grading,
    ]);

    $this->get(route('sitemap'))->assertOk()
        ->assertSee($article->url(), false)
        ->assertDontSee(route('insights.show', 'sitemap-hub-test'), false);
});

it('allows the major AI crawlers in robots.txt', function () {
    $body = $this->get('/robots.txt')->assertOk()->getContent();

    foreach (['GPTBot', 'ClaudeBot', 'anthropic-ai', 'PerplexityBot', 'Google-Extended', 'CCBot'] as $agent) {
        expect($body)->toContain('User-agent: '.$agent);
    }

    // Each named agent is followed by an Allow, never a blanket Disallow.
    expect($body)
        ->not->toContain('Disallow: /'."\n".'User-agent')
        ->toContain('Sitemap: '.route('sitemap'))
        ->toContain('LLM-Content: '.route('llms'));
});

/*
|--------------------------------------------------------------------------
| Model behaviour
|--------------------------------------------------------------------------
*/

it('drops incomplete faq and source rows', function () {
    $article = Article::factory()->create([
        'faqs' => [['question' => 'Q', 'answer' => ''], ['question' => 'Real?', 'answer' => 'Yes.']],
        'sources' => [['url' => 'javascript:alert(1)', 'label' => 'Bad'], ['url' => 'https://example.org', 'label' => '']],
    ]);

    expect($article->faqPairs())->toHaveCount(1)
        ->and($article->faqPairs()[0]['question'])->toBe('Real?')
        ->and($article->sourceList())->toHaveCount(1)
        ->and($article->sourceList()[0]['label'])->toBe('https://example.org');
});

it('estimates reading time from the body when none is stored', function () {
    $article = Article::factory()->create([
        'reading_minutes' => null,
        'body' => str_repeat('word ', 440),
    ]);

    expect($article->readingTime())->toBe(2);
});
