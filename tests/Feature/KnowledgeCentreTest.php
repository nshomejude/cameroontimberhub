<?php

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
