<?php

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\Species;

beforeEach(function () {
    $this->dir = storage_path('framework/testing/content-articles');

    if (is_dir($this->dir)) {
        array_map('unlink', glob($this->dir.'/*') ?: []);
    } else {
        mkdir($this->dir, 0777, true);
    }
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
});

function writeArticleFixture(string $dir, string $name, string $contents, ?int $mtime = null): string
{
    $path = $dir.'/'.$name;
    file_put_contents($path, $contents);

    if ($mtime !== null) {
        touch($path, $mtime);
    }

    return $path;
}

function fixtureBody(string $extra = ''): string
{
    return <<<MD
    ---
    title: "EUDR for buyers: the short version"
    slug: eudr-short
    category: regulation
    excerpt: >
      A folded block scalar that spans
      two source lines.
    meta_title: EUDR for buyers
    meta_description: What importers must collect.
    keywords:
      - EUDR
      - due diligence
    status: published
    published_at: 2026-08-01
    reading_minutes: 7
    faqs:
      - question: Does it apply to goods on the water?
        answer: >
          Placement on the market is the trigger, not the shipping date.
    sources:
      - url: https://eur-lex.europa.eu/eli/reg/2023/1115/oj
        label: Regulation (EU) 2023/1115
    ---

    ## First heading

    Body text.{$extra}
    MD;
}

it('creates an article from a markdown file', function () {
    writeArticleFixture($this->dir, 'eudr-short.md', fixtureBody());

    $this->artisan('articles:import', ['--path' => $this->dir])->assertSuccessful();

    $article = Article::where('slug', 'eudr-short')->firstOrFail();

    expect($article->title)->toBe('EUDR for buyers: the short version')
        ->and($article->category)->toBe(ArticleCategory::Regulation)
        ->and($article->status)->toBe(ArticleStatus::Published)
        ->and($article->published_at->toDateString())->toBe('2026-08-01')
        ->and($article->excerpt)->toBe('A folded block scalar that spans two source lines.')
        ->and($article->keywords)->toBe(['EUDR', 'due diligence'])
        ->and($article->reading_minutes)->toBe(7)
        ->and($article->faqPairs())->toHaveCount(1)
        ->and($article->faqPairs()[0]['answer'])->toBe('Placement on the market is the trigger, not the shipping date.')
        ->and($article->sourceList())->toHaveCount(1)
        ->and($article->body)->toContain('## First heading')
        // The byline defaults to the organisation — no author is invented.
        ->and($article->author_name)->toBeNull()
        ->and($article->byline)->toBe(config('app.name').' editorial');
});

it('is idempotent when nothing on disk has changed', function () {
    writeArticleFixture($this->dir, 'eudr-short.md', fixtureBody());

    $this->artisan('articles:import', ['--path' => $this->dir])->assertSuccessful();
    $first = Article::where('slug', 'eudr-short')->firstOrFail();

    $this->artisan('articles:import', ['--path' => $this->dir])
        ->expectsOutputToContain('0 created, 0 updated, 1 unchanged')
        ->assertSuccessful();

    expect(Article::count())->toBe(1)
        ->and(Article::first()->updated_at->eq($first->updated_at))->toBeTrue();
});

it('updates an article when the source file changes', function () {
    $path = writeArticleFixture($this->dir, 'eudr-short.md', fixtureBody());
    $this->artisan('articles:import', ['--path' => $this->dir])->assertSuccessful();

    file_put_contents($path, str_replace('Body text.', 'Rewritten body text.', fixtureBody()));
    touch($path, time() + 60);

    $this->artisan('articles:import', ['--path' => $this->dir])
        ->expectsOutputToContain('0 created, 1 updated')
        ->assertSuccessful();

    expect(Article::count())->toBe(1)
        ->and(Article::first()->body)->toContain('Rewritten body text.');
});

it('refuses to clobber an admin edit, and honours --force', function () {
    $path = writeArticleFixture($this->dir, 'eudr-short.md', fixtureBody());
    $this->artisan('articles:import', ['--path' => $this->dir])->assertSuccessful();

    // A human saves the record in the admin...
    $article = Article::where('slug', 'eudr-short')->firstOrFail();
    $article->forceFill(['title' => 'Hand-edited title', 'updated_at' => now()->addMinutes(5)])->save();

    // ...and the file changes too.
    file_put_contents($path, fixtureBody(' Updated.'));
    touch($path, time() + 600);

    $this->artisan('articles:import', ['--path' => $this->dir])
        ->expectsOutputToContain('1 conflicts')
        ->assertSuccessful();

    expect(Article::first()->title)->toBe('Hand-edited title');

    $this->artisan('articles:import', ['--path' => $this->dir, '--force' => true])
        ->expectsOutputToContain('1 updated')
        ->assertSuccessful();

    expect(Article::first()->title)->toBe('EUDR for buyers: the short version');
});

it('writes nothing on a dry run', function () {
    writeArticleFixture($this->dir, 'eudr-short.md', fixtureBody());

    $this->artisan('articles:import', ['--path' => $this->dir, '--dry-run' => true])->assertSuccessful();

    expect(Article::count())->toBe(0);
});

it('resolves related species slugs to ids and drops unknown ones', function () {
    $species = Species::factory()->create(['slug' => 'iroko', 'common_name' => 'Iroko']);

    writeArticleFixture($this->dir, 'eudr-short.md', str_replace(
        "keywords:\n  - EUDR",
        "related_species:\n  - iroko\n  - not-a-species\nkeywords:\n  - EUDR",
        fixtureBody()
    ));

    $this->artisan('articles:import', ['--path' => $this->dir])->assertSuccessful();

    expect(Article::first()->related_species_ids)->toBe([$species->id]);
});

it('fails a file with an unknown category and leaves the rest importable', function () {
    writeArticleFixture($this->dir, 'bad.md', "---\ntitle: Bad\ncategory: nonsense\n---\n\nBody.");
    writeArticleFixture($this->dir, 'eudr-short.md', fixtureBody());

    $this->artisan('articles:import', ['--path' => $this->dir])->assertFailed();

    expect(Article::count())->toBe(1)
        ->and(Article::first()->slug)->toBe('eudr-short');
});

it('fails a file with no frontmatter block', function () {
    writeArticleFixture($this->dir, 'raw.md', "# Just markdown\n\nNo frontmatter here.");

    $this->artisan('articles:import', ['--path' => $this->dir])->assertFailed();

    expect(Article::count())->toBe(0);
});

it('ignores the README and underscore-prefixed files', function () {
    writeArticleFixture($this->dir, 'README.md', "# Contract\n\nNot an article.");
    writeArticleFixture($this->dir, '_draft.md', fixtureBody());

    $this->artisan('articles:import', ['--path' => $this->dir])->assertSuccessful();

    expect(Article::count())->toBe(0);
});

it('imports the shipped content/articles directory cleanly', function () {
    Species::factory()->create(['slug' => 'iroko', 'common_name' => 'Iroko']);
    Species::factory()->create(['slug' => 'ayous', 'common_name' => 'Ayous']);
    Species::factory()->create(['slug' => 'sipo', 'common_name' => 'Sipo']);

    $this->artisan('articles:import')->assertSuccessful();

    expect(Article::count())->toBeGreaterThan(0);
});
