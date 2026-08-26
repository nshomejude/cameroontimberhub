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

it('hides an unpublished term from the sitemap and llms.txt', function () {
    GlossaryTerm::factory()->create(['term' => 'Secret Term', 'slug' => 'secret-term', 'is_published' => false]);

    $this->get(route('sitemap'))->assertOk()->assertDontSee(route('glossary.show', 'secret-term'), false);
    $this->get('/llms.txt')->assertOk()->assertDontSee(route('glossary.show', 'secret-term'), false);
});

it('never surfaces an unpublished term through search', function () {
    GlossaryTerm::factory()->create(['term' => 'Hidden Kiln Term', 'slug' => 'hidden-kiln-term', 'is_published' => false]);

    $this->get(route('glossary.index', ['q' => 'kiln']))->assertOk()->assertDontSee('Hidden Kiln Term');
});
