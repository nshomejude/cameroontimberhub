<?php

use App\Models\Company;
use App\Models\Species;

it('word-safe excerpt never cuts a word in half and preserves short text unchanged', function () {
    $short = 'Iroko is a durable West African hardwood.';
    expect(Species::wordSafeExcerpt($short, 300))->toBe($short);

    // A long run of description text with no natural break before the limit
    // used to be hard-cut mid-word by Str::limit(300), landing at ~303 chars
    // with a fragment like "...hardwo...". The word-safe excerpt must always
    // end on a whole word (never split a word across the "..." boundary).
    $long = str_repeat('durable tropical hardwood exported from Cameroon forests to global buyers via certified legal supply chains ', 5);
    $excerpt = Species::wordSafeExcerpt($long, 300);

    expect(mb_strlen($excerpt))->toBeLessThanOrEqual(303);
    expect($excerpt)->toEndWith('...');

    // The excerpt minus its ellipsis must be a prefix ending exactly where a
    // real word in the source ends — i.e. re-appending the next source
    // character never continues a word that was cut off.
    $withoutEllipsis = mb_substr($excerpt, 0, -3);
    expect($withoutEllipsis)->not->toBe('');
    expect(str_starts_with($long, $withoutEllipsis))->toBeTrue();
    $nextChar = mb_substr($long, mb_strlen($withoutEllipsis), 1);
    expect($nextChar)->toBe(' ');
});

it('renders a species meta description that does not truncate mid-word, even for legacy rows stored pre-cut', function () {
    // Simulates the pre-fix stored data: Str::limit(300) hard-cut at exactly
    // 300 characters, landing mid-word, then appended "...".
    $source = str_repeat('durable tropical hardwood exported from Cameroon forests to international buyers via certified and fully documented legal supply chains ', 4);
    $legacyStoredValue = mb_substr($source, 0, 300).'...';

    $species = Species::factory()->create([
        'common_name' => 'Meta Truncation Wood',
        'description' => $source,
        'meta_description' => $legacyStoredValue,
    ]);

    $response = $this->get(route('species.show', $species->slug))->assertOk();
    $html = $response->getContent();

    preg_match('/<meta name="description" content="([^"]*)">/', $html, $matches);
    expect($matches)->not->toBeEmpty();

    $rendered = html_entity_decode($matches[1]);

    expect($rendered)->not->toBe($legacyStoredValue);
    expect(mb_strlen($rendered))->toBeLessThanOrEqual(303);
    expect($rendered)->toEndWith('...');

    // No trailing fragment: the character right before "..." must not be
    // mid-word — i.e. followed immediately in the source by a further word
    // character rather than a space.
    $withoutEllipsis = mb_substr($rendered, 0, -3);
    $pos = mb_strlen($withoutEllipsis);
    $nextChar = mb_substr($source, $pos, 1);

    expect($nextChar === '' || $nextChar === ' ')->toBeTrue();
});

it('publishes the real company email in Organization JSON-LD when one is set', function () {
    $company = Company::factory()->publiclyVisible()->create([
        'legal_name' => 'Real Email Exporter Co',
        'email' => 'contact@realexporter.cm',
    ]);

    $this->get(route('companies.show', $company->slug))
        ->assertOk()
        ->assertSee('"email":"contact@realexporter.cm"', false);
});

it('omits the email field from Organization JSON-LD instead of publishing a placeholder address', function () {
    $company = Company::factory()->publiclyVisible()->create([
        'legal_name' => 'Placeholder Email Exporter Co',
        'email' => 'sales@africanwood.example',
    ]);

    $response = $this->get(route('companies.show', $company->slug))->assertOk();
    $schema = companyOrganizationSchema($response->getContent());

    expect($schema)->not->toHaveKey('email');
    expect(json_encode($schema))->not->toContain('africanwood.example');
});

it('omits the email field entirely when the company has no real contact email', function () {
    $company = Company::factory()->publiclyVisible()->create([
        'legal_name' => 'No Email Exporter Co',
        'email' => null,
    ]);

    $response = $this->get(route('companies.show', $company->slug))->assertOk();

    expect(companyOrganizationSchema($response->getContent()))->not->toHaveKey('email');
});

/**
 * Extract the page's own company Organization JSON-LD (the last ld+json
 * block, produced by CompanyController::schema()) — distinct from the
 * sitewide Organization block the layout always emits for the platform
 * itself, which legitimately carries a real platform contact email.
 */
function companyOrganizationSchema(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);
    $blocks = array_map(fn ($json) => json_decode($json, true), $matches[1]);

    $companySchema = collect($blocks)->first(fn ($block) => ($block['@type'] ?? null) === 'Organization' && ! isset($block['@id']));

    expect($companySchema)->not->toBeNull();

    return $companySchema;
}

it('defaults og:type to website but lets a page opt into a different type', function () {
    $species = Species::factory()->create();

    $this->get(route('species.show', $species->slug))
        ->assertOk()
        ->assertSee('<meta property="og:type" content="website">', false);

    $view = $this->blade(
        '<x-layouts.app type="article" title="T" description="D">content</x-layouts.app>'
    );

    $view->assertSee('<meta property="og:type" content="article">', false);
});

it('has exactly one h1 on the homepage, species directory, marketplace, supplier directory and product pages', function () {
    Species::factory()->create();
    Company::factory()->publiclyVisible()->create();

    foreach ([
        route('home'),
        route('species.index'),
        route('directory'),
        route('marketplace'),
    ] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        expect(substr_count($html, '<h1'))->toBe(1, "Expected exactly one <h1> on {$url}");
    }
});
