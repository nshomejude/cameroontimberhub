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
