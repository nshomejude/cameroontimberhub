<?php

use App\Enums\KnowledgeHub;
use App\Models\Article;
use App\Models\SlugRedirect;

it('serves both EUDR articles under the compliance hub', function () {
    $this->artisan('articles:import');

    foreach (['eu-deforestation-regulation-eudr-explained', 'eudr-compliance-cameroon-timber'] as $slug) {
        $article = Article::published()->where('slug', $slug)->first();

        expect($article)->not->toBeNull()
            ->and($article->hub)->toBe(KnowledgeHub::Compliance);

        $this->get(route('knowledge.article', ['hub' => 'compliance', 'slug' => $slug]))->assertOk();
    }
});

it('records a 301 slug redirect from the old insights URL for every hubbed article', function () {
    $this->artisan('articles:import');

    foreach (['eu-deforestation-regulation-eudr-explained', 'eudr-compliance-cameroon-timber'] as $slug) {
        expect(SlugRedirect::where('from_slug', "insights/{$slug}")->exists())->toBeTrue();

        $this->get("/insights/{$slug}")
            ->assertStatus(301)
            ->assertRedirect(route('knowledge.article', ['hub' => 'compliance', 'slug' => $slug]));
    }
});

it('lists both articles on the compliance hub page', function () {
    $this->artisan('articles:import');

    $this->get(route('knowledge.hub', 'compliance'))
        ->assertOk()
        ->assertSee('EU Deforestation Regulation', false);
});

it('redirects /insights/{slug} to the hub URL even without a recorded slug redirect', function () {
    $article = Article::factory()->create([
        'slug' => 'controller-hub-redirect',
        'hub' => KnowledgeHub::Grading,
    ]);

    expect(SlugRedirect::where('from_slug', 'insights/controller-hub-redirect')->exists())->toBeFalse();

    $this->get('/insights/controller-hub-redirect')
        ->assertStatus(301)
        ->assertRedirect($article->url());
});

it('still renders an unhubbed article at its insights URL', function () {
    $article = Article::factory()->create([
        'slug' => 'plain-insights-article',
        'hub' => null,
    ]);

    $this->get('/insights/plain-insights-article')
        ->assertOk()
        ->assertSee($article->title, false);
});

it('fails a markdown file whose hub is not a real knowledge hub', function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ctimber-import-'.uniqid();
    mkdir($dir, 0777, true);

    file_put_contents($dir.'/bogus-hub.md', <<<'MD'
    ---
    title: "A file with a bogus hub"
    slug: bogus-hub
    category: guides
    hub: not-a-hub
    status: published
    published_at: 2026-08-26
    ---

    Body text.
    MD);

    try {
        $this->artisan('articles:import', ['--path' => $dir])
            ->assertExitCode(1);

        expect(Article::where('slug', 'bogus-hub')->exists())->toBeFalse();
    } finally {
        unlink($dir.'/bogus-hub.md');
        rmdir($dir);
    }
});

it('records a redirect from the old hub URL when a hub-to-hub move happens on a plain model save', function () {
    $article = Article::factory()->create([
        'slug' => 'moved-between-hubs',
        'hub' => KnowledgeHub::Compliance,
    ]);

    $oldUrl = $article->url();

    // No importer, no admin panel — the bare model save every other code path
    // ultimately performs.
    $article->hub = KnowledgeHub::Grading;
    $article->save();

    expect(SlugRedirect::where('from_slug', 'knowledge/compliance/moved-between-hubs')->exists())->toBeTrue();

    $this->get('/knowledge/compliance/moved-between-hubs')
        ->assertStatus(301)
        ->assertRedirect($article->fresh()->url());

    $this->get($article->fresh()->url())->assertOk();
    expect($oldUrl)->not->toBe($article->fresh()->url());
});

it('records a redirect from the old path when a hubbed article changes slug', function () {
    $article = Article::factory()->create([
        'slug' => 'slug-before-rename',
        'hub' => KnowledgeHub::Grading,
    ]);

    $article->slug = 'slug-after-rename';
    $article->save();

    $this->get('/knowledge/grading/slug-before-rename')
        ->assertStatus(301)
        ->assertRedirect($article->fresh()->url());
});

it('re-aims an earlier redirect at the new URL instead of leaving it pointing into a 404', function () {
    $article = Article::factory()->create([
        'slug' => 'twice-moved',
        'hub' => KnowledgeHub::Compliance,
    ]);

    $article->hub = KnowledgeHub::Grading;
    $article->save();

    $article->hub = KnowledgeHub::Logistics;
    $article->save();

    // Both abandoned URLs must land on the current one, in a single hop.
    foreach (['knowledge/compliance/twice-moved', 'knowledge/grading/twice-moved'] as $path) {
        $this->get('/'.$path)
            ->assertStatus(301)
            ->assertRedirect($article->fresh()->url());
    }

    $this->get($article->fresh()->url())->assertOk();
});

it('leaves no self-referential redirect when an article is pulled back out of its hub', function () {
    $article = Article::factory()->create([
        'slug' => 'unhubbed-by-editor',
        'hub' => KnowledgeHub::Compliance,
    ]);

    // The row an earlier import/move would have left behind.
    SlugRedirect::record('insights/unhubbed-by-editor', $article->url());

    $article->hub = null;
    $article->save();

    expect(SlugRedirect::where('from_slug', 'insights/unhubbed-by-editor')->exists())->toBeFalse();

    $this->get('/insights/unhubbed-by-editor')->assertOk();
});

it('applies the observer to hub moves made by the importer itself', function () {
    // Empirical check that Eloquent model events fire from articles:import —
    // the importer no longer records the "moved off" redirect itself, so this
    // row can only exist if ArticleObserver ran during the command.
    Article::factory()->create([
        'slug' => 'importer-moves-hub',
        'hub' => KnowledgeHub::Compliance,
    ]);

    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ctimber-import-'.uniqid();
    mkdir($dir, 0777, true);

    file_put_contents($dir.'/importer-moves-hub.md', <<<'MD'
    ---
    title: "An article the importer moves between hubs"
    slug: importer-moves-hub
    category: guides
    hub: grading
    status: published
    published_at: 2026-08-26
    ---

    Body text.
    MD);

    try {
        $this->artisan('articles:import', ['--path' => $dir, '--force' => true])->assertExitCode(0);

        expect(Article::where('slug', 'importer-moves-hub')->first()->hub)->toBe(KnowledgeHub::Grading);

        $this->get('/knowledge/compliance/importer-moves-hub')
            ->assertStatus(301)
            ->assertRedirect(route('knowledge.article', ['hub' => 'grading', 'slug' => 'importer-moves-hub']));

        // And the importer's own legacy claim is still made.
        expect(SlugRedirect::where('from_slug', 'insights/importer-moves-hub')->exists())->toBeTrue();
    } finally {
        unlink($dir.'/importer-moves-hub.md');
        rmdir($dir);
    }
});

it('drops a stale redirect that would point an article away from its own URL', function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ctimber-import-'.uniqid();
    mkdir($dir, 0777, true);

    file_put_contents($dir.'/unhubbed-again.md', <<<'MD'
    ---
    title: "An article pulled back out of its hub"
    slug: unhubbed-again
    category: guides
    status: published
    published_at: 2026-08-26
    ---

    Body text.
    MD);

    // The state left behind by an earlier import, when the piece was hubbed.
    SlugRedirect::record('insights/unhubbed-again', '/knowledge/compliance/unhubbed-again');

    try {
        $this->artisan('articles:import', ['--path' => $dir])->assertExitCode(0);

        expect(SlugRedirect::where('from_slug', 'insights/unhubbed-again')->exists())->toBeFalse();

        $this->get('/insights/unhubbed-again')->assertOk();
    } finally {
        unlink($dir.'/unhubbed-again.md');
        rmdir($dir);
    }
});
