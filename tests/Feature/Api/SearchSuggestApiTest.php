<?php

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/search/suggest — instant (type-ahead) search.
 */
function suggestFixture(): array
{
    $company = Company::factory()->publiclyVisible()->create(['legal_name' => 'Scierie Nkongsamba Sarl', 'trade_name' => null]);
    $hidden = Company::factory()->create(['legal_name' => 'Scierie Cachée Sarl', 'status' => CompanyStatus::Pending]);

    $iroko = Species::factory()->create(['common_name' => 'Iroko', 'scientific_name' => 'Milicia excelsa']);
    $ebene = Species::factory()->create(['common_name' => 'Ébène', 'scientific_name' => 'Diospyros crassiflora']);
    Species::factory()->unpublished()->create(['common_name' => 'Irokosecret']);

    $beams = Product::factory()->active()->for($company)->for($iroko)->create(['name' => 'Sawn beams 50x150']);
    $ebony = Product::factory()->active()->for($company)->for($ebene)->create(['name' => 'Billets d\'ébène']);
    Product::factory()->draft()->for($company)->for($iroko)->create(['name' => 'Draft Iroko Board']);
    Product::factory()->active()->for($hidden)->for($iroko)->create(['name' => 'Hidden Iroko Board']);

    return compact('company', 'hidden', 'iroko', 'ebene', 'beams', 'ebony');
}

it('finds a product by its species name even when the product name lacks it', function () {
    $f = suggestFixture();

    $response = $this->getJson('/api/v1/search/suggest?q=iroko')->assertOk();

    expect(collect($response->json('data.products'))->pluck('slug')->all())->toBe([$f['beams']->slug])
        ->and($response->json('data.products.0'))->toHaveKeys(['id', 'slug', 'name', 'species', 'company_name', 'image_url', 'price_label', 'url'])
        ->and($response->json('data.products.0.species'))->toBe('Iroko')
        ->and(collect($response->json('data.species'))->pluck('slug')->all())->toBe([$f['iroko']->slug]);
});

it('finds products and suppliers by the company name', function () {
    $f = suggestFixture();

    $response = $this->getJson('/api/v1/search/suggest?q=nkongsamba')->assertOk();

    expect($response->json('data.products'))->toHaveCount(2)
        ->and($response->json('data.suppliers.0.slug'))->toBe($f['company']->slug)
        ->and($response->json('data.suppliers.0'))->toHaveKeys(['slug', 'name', 'logo_url', 'city', 'verified']);
});

it('matches case- and accent-insensitively in both directions', function () {
    $f = suggestFixture();

    foreach (['ebene', 'ÉBÈNE', 'Ébe'] as $q) {
        $response = $this->getJson('/api/v1/search/suggest?q='.urlencode($q))->assertOk();

        expect(collect($response->json('data.species'))->pluck('slug')->all())->toBe([$f['ebene']->slug], $q)
            ->and(collect($response->json('data.products'))->pluck('slug')->all())->toBe([$f['ebony']->slug], $q);
    }

    $this->getJson('/api/v1/search/suggest?q=cachee')->assertOk()->assertJsonCount(0, 'data.suppliers');
});

it('never exposes drafts, non-public companies or unpublished species', function () {
    suggestFixture();

    $body = json_encode($this->getJson('/api/v1/search/suggest?q=scierie')->assertOk()->json()
        + $this->getJson('/api/v1/search/suggest?q=iroko')->assertOk()->json());
    $body .= json_encode($this->getJson('/api/v1/search/suggest?q=board')->json());

    expect($body)->not->toContain('Cach')
        ->not->toContain('Draft Iroko')
        ->not->toContain('Hidden Iroko')
        ->not->toContain('Irokosecret')
        ->not->toContain('search_vector');
});

it('returns empty groups below the minimum length instead of erroring', function () {
    suggestFixture();

    $this->getJson('/api/v1/search/suggest?q=i')
        ->assertOk()
        ->assertExactJson(['data' => ['products' => [], 'suppliers' => [], 'species' => []], 'meta' => ['query' => 'i', 'min_chars' => 2, 'search_url' => url('/search').'?q=i']]);
});

it('honours limit and types, and rejects bad values', function () {
    $company = Company::factory()->publiclyVisible()->create(['legal_name' => 'Limit Test Sarl']);
    Product::factory()->count(8)->active()->for($company)->sequence(fn ($s) => ['name' => 'Ayous plank '.$s->index])->create();

    $this->getJson('/api/v1/search/suggest?q=ayous')->assertOk()->assertJsonCount(5, 'data.products');
    $this->getJson('/api/v1/search/suggest?q=ayous&limit=2&types=products')->assertOk()
        ->assertJsonCount(2, 'data.products')->assertJsonCount(0, 'data.suppliers');
    $this->getJson('/api/v1/search/suggest?q=ayous&limit=50')->assertStatus(422);
    $this->getJson('/api/v1/search/suggest?q=ayous&types=orders')->assertStatus(422);
});

it('treats LIKE wildcards in the query literally', function () {
    suggestFixture();

    $this->getJson('/api/v1/search/suggest?q=%25%25')->assertOk()->assertJsonCount(0, 'data.products');
});

it('runs a bounded number of queries and caches by normalised query', function () {
    suggestFixture();

    DB::enableQueryLog();
    $this->getJson('/api/v1/search/suggest?q=iroko')->assertOk();
    $first = count(DB::getQueryLog());
    DB::flushQueryLog();
    $this->getJson('/api/v1/search/suggest?q=%20IROKO%20')->assertOk();
    $second = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($first)->toBeLessThanOrEqual(6)->and($second)->toBe(0);
});

it('sends a short public cache header with an ETag and honours If-None-Match', function () {
    suggestFixture();

    $response = $this->getJson('/api/v1/search/suggest?q=iroko')->assertOk();
    $etag = $response->headers->get('ETag');

    expect($etag)->not->toBeNull()
        ->and($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60');

    $this->getJson('/api/v1/search/suggest?q=iroko', ['If-None-Match' => $etag])->assertStatus(304);
});

it('renders the accessible instant-search combobox in the public header', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('role="combobox"', false)
        ->assertSee('aria-controls="header-search-listbox"', false)
        ->assertSee('id="mobile-header-search"', false)
        ->assertSee('action="'.url('/search').'"', false);
});

it('has its own 120/min throttle', function () {
    for ($i = 0; $i < 120; $i++) {
        $this->getJson('/api/v1/search/suggest?q=x')->assertOk();
    }

    $this->getJson('/api/v1/search/suggest?q=x')->assertStatus(429);
});
