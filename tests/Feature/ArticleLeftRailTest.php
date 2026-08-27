<?php

use App\Enums\ArticleStatus;
use App\Enums\KnowledgeHub;
use App\Models\Article;
use Illuminate\Support\Str;

/** Collapse HTML whitespace so attribute adjacency can be asserted. */
function railHtml(string $url): string
{
    return (string) preg_replace('/\s+/', ' ', test()->get($url)->assertOk()->getContent());
}

it('renders the left rail on a hubbed article with that hub marked current', function () {
    Article::factory()->create([
        'title' => 'Incoterms For Timber Buyers', 'slug' => 'incoterms-rail', 'hub' => KnowledgeHub::Buying,
    ]);

    $html = railHtml(route('knowledge.article', ['hub' => 'buying', 'slug' => 'incoterms-rail']));

    expect($html)->toContain('aria-label="Knowledge Centre"');

    // Every hub is listed, and only the article's own hub is marked current.
    foreach (KnowledgeHub::cases() as $hub) {
        expect($html)->toContain('href="'.$hub->url().'"');
    }

    expect($html)->toContain('href="'.KnowledgeHub::Buying->url().'" aria-current="true"')
        ->and($html)->not->toContain('href="'.KnowledgeHub::Export->url().'" aria-current="true"');
});

it('lists published hub siblings and marks the current article', function () {
    Article::factory()->create([
        'title' => 'Incoterms For Timber Buyers', 'slug' => 'incoterms-rail', 'hub' => KnowledgeHub::Buying,
    ]);
    Article::factory()->create([
        'title' => 'Verifying A Supplier', 'slug' => 'verifying-a-supplier', 'hub' => KnowledgeHub::Buying,
    ]);
    Article::factory()->create([
        'title' => 'Not A Sibling Piece', 'slug' => 'not-a-sibling', 'hub' => KnowledgeHub::Export,
    ]);

    $html = railHtml(route('knowledge.article', ['hub' => 'buying', 'slug' => 'incoterms-rail']));

    expect($html)->toContain('Verifying A Supplier')
        ->and($html)->toContain(route('knowledge.article', ['hub' => 'buying', 'slug' => 'verifying-a-supplier']))
        ->and($html)->toContain('href="'.route('knowledge.article', ['hub' => 'buying', 'slug' => 'incoterms-rail']).'" aria-current="page"')
        // The other hub's article is not a sibling: it never appears inside the
        // rail's sublist, which is nested under the current hub's own <li>.
        ->and($html)->not->toContain(route('knowledge.article', ['hub' => 'export', 'slug' => 'not-a-sibling']).'" aria-current');

    $rail = Str::between($html, 'aria-label="Knowledge Centre"', '</aside>');
    expect($rail)->toContain('Verifying A Supplier')
        ->and($rail)->not->toContain('Not A Sibling Piece');
});

it('never leaks an unpublished sibling into the rail', function () {
    Article::factory()->create([
        'title' => 'Incoterms For Timber Buyers', 'slug' => 'incoterms-rail', 'hub' => KnowledgeHub::Buying,
    ]);
    Article::factory()->create([
        'title' => 'Draft Sibling Piece', 'slug' => 'draft-sibling', 'hub' => KnowledgeHub::Buying,
        'status' => ArticleStatus::Draft, 'published_at' => null,
    ]);

    $html = railHtml(route('knowledge.article', ['hub' => 'buying', 'slug' => 'incoterms-rail']));

    expect($html)->not->toContain('Draft Sibling Piece')
        ->and($html)->not->toContain('draft-sibling');
});

it('shows the hub list with nothing marked current on an unhubbed news article', function () {
    Article::factory()->create([
        'title' => 'Plain News Piece', 'slug' => 'plain-news-rail', 'hub' => null,
    ]);

    $html = railHtml(route('insights.show', 'plain-news-rail'));

    expect($html)->toContain('aria-label="Knowledge Centre"')
        ->and($html)->toContain('href="'.KnowledgeHub::Buying->url().'"');

    foreach (KnowledgeHub::cases() as $hub) {
        expect($html)->not->toContain('href="'.$hub->url().'" aria-current="true"');
    }
});
