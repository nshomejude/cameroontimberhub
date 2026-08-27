<?php

use App\Models\Article;

/**
 * Blade compiles a bare `@context` as its own directive -- even inside a
 * {!! !!} expression -- which silently replaced the JSON-LD key with raw PHP
 * source. The blocks still parsed as JSON, so nothing looked broken, but with
 * no @context Google discards the entire block. These tests pin the rendered
 * output rather than the source, because that is where the damage showed.
 */
it('emits a real @context on every JSON-LD block, never compiled Blade source', function () {
    $body = $this->get('/')->assertOk()->getContent();

    expect($body)
        ->not->toContain('__contextArgs')
        ->not->toContain('context()->has');

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $body, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $block) {
        $decoded = json_decode(html_entity_decode($block), true);

        expect($decoded)->toBeArray()
            ->and($decoded)->toHaveKey('@context')
            ->and($decoded['@context'])->toBe('https://schema.org');
    }
});

it('keeps every JSON-LD block on an article contextualised', function () {
    // An article page stacks the most blocks -- the sitewide graph, the
    // breadcrumb trail and the Article node -- so it is the strictest case.
    $article = Article::factory()->create(['slug' => 'structured-data-context-test']);

    $body = $this->get($article->url())->assertOk()->getContent();

    expect($body)->not->toContain('__contextArgs');

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $body, $matches);

    $contexts = collect($matches[1])
        ->map(fn (string $block) => json_decode(html_entity_decode($block), true))
        ->filter()
        ->pluck('@context')
        ->unique()
        ->values();

    expect($contexts->all())->toBe(['https://schema.org']);
});
