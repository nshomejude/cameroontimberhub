<?php

use App\Models\GlossaryTerm;

it('lists published glossary terms alphabetically', function () {
    GlossaryTerm::factory()->create(['term' => 'Board Foot', 'slug' => 'board-foot', 'is_published' => true]);
    GlossaryTerm::factory()->create(['term' => 'Air Drying', 'slug' => 'air-drying', 'is_published' => true]);
    GlossaryTerm::factory()->create(['term' => 'Chain of Custody', 'slug' => 'chain-of-custody', 'is_published' => true]);

    $response = $this->get(route('glossary.index'))
        ->assertOk()
        ->assertSee('Air Drying')
        ->assertSee('Board Foot')
        ->assertSee('Chain of Custody');

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

it('cannot be broken out of the JSON-LD block by admin-authored content', function () {
    GlossaryTerm::factory()->create([
        'term' => 'Hostile Term',
        'slug' => 'hostile-term',
        'definition' => 'Breaks out?</script><script>alert(1)</script>',
        'is_published' => true,
    ]);

    $body = $this->get(route('glossary.show', 'hostile-term'))->assertOk()->getContent();

    // The raw closing tag must never survive into the document: if it does, the
    // LD+JSON block is closed early and the following markup runs as script.
    expect($body)->not->toContain('</script><script>alert(1)</script>');

    // ...and the schema block must still be parseable JSON-LD containing the
    // escaped text, so the fix did not simply drop the description.
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $body, $m);
    $block = collect($m[1])->first(fn (string $json): bool => str_contains($json, 'DefinedTerm'));
    expect($block)->not->toBeNull();
    $decoded = json_decode($block, true);
    expect($decoded)->toBeArray()
        ->and($decoded['description'])->toContain('alert(1)');
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

/**
 * @return list<array<string, mixed>>
 */
function glossaryLdJson(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return collect($m[1])
        ->map(fn (string $json) => json_decode($json, true))
        ->filter(fn ($decoded): bool => is_array($decoded))
        ->values()
        ->all();
}

it('emits a DefinedTermSet on the glossary index whose @id is the one term pages reference', function () {
    GlossaryTerm::factory()->create([
        'term' => 'Bill Of Lading',
        'slug' => 'bill-of-lading',
        'is_published' => true,
    ]);

    // The @id a term page points at...
    $termSchema = collect(glossaryLdJson($this->get(route('glossary.show', 'bill-of-lading'))->assertOk()->getContent()))
        ->first(fn (array $node): bool => ($node['@type'] ?? null) === 'DefinedTerm');

    expect($termSchema)->not->toBeNull();

    $referencedId = $termSchema['inDefinedTermSet']['@id'] ?? null;
    expect($referencedId)->toBeString();
    expect($referencedId)->not->toBeEmpty();

    // ...must be defined by a node the index actually emits. Compared, never
    // hardcoded twice, so the two sides cannot drift apart.
    $indexNodes = glossaryLdJson($this->get(route('glossary.index'))->assertOk()->getContent());

    $set = collect($indexNodes)->first(fn (array $node): bool => ($node['@id'] ?? null) === $referencedId);

    expect($set)->not->toBeNull()
        ->and((array) $set['@type'])->toContain('DefinedTermSet')
        ->and($set['url'])->toBe(route('glossary.index'));

    // And the set lists the term, so the relationship resolves both ways.
    expect(collect($set['mainEntity']['itemListElement'])->pluck('url')->all())
        ->toContain(route('glossary.show', 'bill-of-lading'));
});
