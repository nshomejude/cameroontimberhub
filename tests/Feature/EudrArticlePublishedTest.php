<?php

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Models\Article;

/**
 * End-to-end proof that a real, shipped content file in `content/articles/`
 * imports and renders: the EUDR explainer goes in as markdown-with-frontmatter,
 * comes out as a published article page with its citation and disclaimer, and
 * appears in the sitemap. Every other article test uses fixtures — this one
 * deliberately uses the file the site actually publishes.
 */
const EUDR_SLUG = 'eu-deforestation-regulation-eudr-explained';

beforeEach(function () {
    expect(base_path('content/articles/'.EUDR_SLUG.'.md'))->toBeFile();

    $this->artisan('articles:import')->assertSuccessful();

    $this->article = Article::where('slug', EUDR_SLUG)->firstOrFail();
});

it('imports the EUDR explainer as a published compliance article', function () {
    expect($this->article->status)->toBe(ArticleStatus::Published)
        ->and($this->article->category)->toBe(ArticleCategory::Regulation)
        ->and($this->article->published_at)->not->toBeNull()
        ->and($this->article->sources)->not->toBeEmpty();

    // The citation must be the official EU source, not a secondary summary.
    expect(collect($this->article->sources)->pluck('url')->implode(' '))
        ->toContain('eur-lex.europa.eu');
});

it('renders the article with its legal disclaimer and EUR-Lex citation', function () {
    $this->get($this->article->url())
        ->assertOk()
        ->assertSee('Deforestation Regulation')
        ->assertSee('Regulation (EU) 2023/1115')
        ->assertSee('not legal advice', false)
        ->assertSee('EUR-Lex')
        ->assertSee('https://eur-lex.europa.eu/eli/reg/2023/1115/oj', false);
});

it('resolves the article internal links to real routes rather than raw schemes', function () {
    $response = $this->get($this->article->url())->assertOk();

    $response->assertSee(route('species.index'), false)
        ->assertSee(route('directory'), false)
        ->assertSee(route('rfq.create'), false)
        ->assertDontSee('](rfq:', false);
});

/**
 * The house standard for regulatory content: a "not legal advice" disclaimer as
 * the first rendered element of the body, and every citation carrying an access
 * date. This runs over every shipped `regulation` article rather than just the
 * explainer, so the standard is enforced for every file-authored article. The
 * other authoring path — writing directly in Filament — is held to the same
 * standard by ArticleForm's validation, covered in ArticleAdminTest.
 */
it('holds every shipped regulation article to the legal-content standard', function () {
    $articles = Article::query()
        ->where('category', ArticleCategory::Regulation)
        ->where('status', ArticleStatus::Published)
        ->get();

    expect($articles)->not->toBeEmpty();

    foreach ($articles as $article) {
        expect($article->body)->toContain('not legal advice');

        // First rendered element — the disclaimer sits above the prose.
        expect(trim($article->body))->toStartWith('>');

        expect($article->sources)->not->toBeEmpty();

        foreach ($article->sources as $source) {
            expect(strtolower((string) ($source['label'] ?? '')))
                ->toContain('accessed');
        }

        $this->get($article->url())
            ->assertOk()
            ->assertSee('not legal advice', false);
    }
});

it('includes the EUDR article in the sitemap', function () {
    $this->get(route('sitemap'))
        ->assertOk()
        ->assertSee($this->article->url(), false);
});
