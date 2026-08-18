<?php

use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use App\Services\ProductCatalogueService;
use App\Services\RfqList;

function visibleSupplier(array $attrs = []): Company
{
    return Company::factory()->publiclyVisible()->create($attrs);
}

it('renders the detail page for an active product from a publicly visible company', function () {
    $company = visibleSupplier(['legal_name' => 'Renderable Timber Sarl', 'trade_name' => 'RenderCo']);
    $product = Product::factory()->active()->for($company)->create(['name' => 'Renderable Sawn Timber']);

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Renderable Sawn Timber')
        ->assertSee('RenderCo')
        ->assertSee('Supplier Information');
});

it('404s the detail page for draft products and for hidden companies', function () {
    $visible = visibleSupplier();
    $draft = Product::factory()->for($visible)->create(); // draft status

    $this->get(route('products.show', $draft->slug))->assertNotFound();

    $hidden = Company::factory()->create(); // unverified
    $orphan = Product::factory()->active()->for($hidden)->create();

    $this->get(route('products.show', $orphan->slug))->assertNotFound();
});

it('renders the real spec values recorded against the listing', function () {
    $company = visibleSupplier();

    $product = Product::factory()->active()->for($company)->create([
        'name' => 'Spec Rich Board',
        'product_type' => ProductType::Decking,
        'thickness_mm' => 45,
        'width_min_mm' => 100,
        'width_max_mm' => 250,
        'moisture_content' => '12% - 15% (KD)',
        'grade' => 'Select & Better',
        'certification' => 'Legal Origin Verified',
        'price_amount' => 650000,
        'price_currency' => 'XAF',
        'moq_quantity' => 20,
    ]);

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Decking')
        ->assertSee('45mm')
        ->assertSee('100mm')
        ->assertSee('12% - 15% (KD)')
        ->assertSee('Select &amp; Better', false)
        ->assertSee('Legal Origin Verified')
        ->assertSee('650,000')
        ->assertSee('FCFA');
});

it('hides the rating row entirely when no rating data exists', function () {
    $company = visibleSupplier();
    $product = Product::factory()->active()->for($company)->create([
        'rating' => null, 'reviews_count' => 0, 'buyers_count' => 0,
    ]);

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertDontSee('0 reviews')
        ->assertDontSee('0 buyers')
        ->assertDontSee('Rated ')
        ->assertDontSee('"@type":"AggregateRating"', false);
});

it('renders specification rows derived from the linked species', function () {
    $company = visibleSupplier();
    $species = Species::factory()->create([
        'common_name' => 'Speciwood',
        'scientific_name' => 'Speciosa maxima',
        'density_kg_m3_min' => 640,
        'density_kg_m3_max' => 700,
        'durability_class' => 'Class 1 (Very Durable)',
        'typical_uses' => ['Flooring', 'Boat building'],
    ]);

    $product = Product::factory()->active()->for($company)->for($species)->create([
        'specifications' => null,
    ]);

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Botanical Name')
        ->assertSee('Speciosa maxima')
        ->assertSee('640–700 kg/m³', false)
        ->assertSee('Class 1 (Very Durable)')
        ->assertSee('Boat building');
});

it('excludes the current product and hidden-company products from similar products', function () {
    $company = visibleSupplier();
    $species = Species::factory()->create();

    $product = Product::factory()->active()->for($company)->for($species)->create([
        'name' => 'Anchor Product', 'product_type' => ProductType::SawnTimber,
    ]);
    Product::factory()->active()->for($company)->for($species)->create([
        'name' => 'Sibling Product', 'product_type' => ProductType::SawnTimber,
    ]);

    $hidden = Company::factory()->create();
    Product::factory()->active()->for($hidden)->for($species)->create([
        'name' => 'Invisible Product', 'product_type' => ProductType::SawnTimber,
    ]);

    $response = $this->get(route('products.show', $product->slug))->assertOk();

    $response->assertSee('Sibling Product')->assertDontSee('Invisible Product');

    // The anchor appears as the page's own H1, but never inside Similar Products.
    $similarBlock = str($response->getContent())->after('Similar Products')->value();
    expect($similarBlock)->not->toContain('Anchor Product');
});

it('emits Product JSON-LD priced in XAF, with aggregateRating only when rating data exists', function () {
    $company = visibleSupplier(['legal_name' => 'Schema Supplier Sarl', 'trade_name' => 'SchemaCo']);

    $unrated = Product::factory()->active()->for($company)->create([
        'name' => 'Unrated Schema Board',
        'price_amount' => 400000,
        'price_currency' => 'XAF',
        'rating' => null,
        'reviews_count' => 0,
    ]);

    $this->get(route('products.show', $unrated->slug))
        ->assertOk()
        ->assertSee('"@type":"Product"', false)
        ->assertSee('"priceCurrency":"XAF"', false)
        ->assertSee('"@type":"BreadcrumbList"', false)
        ->assertSee('"name":"SchemaCo"', false)
        ->assertSee('"sku":"'.$unrated->slug.'"', false)
        ->assertDontSee('"@type":"AggregateRating"', false);

    $rated = Product::factory()->active()->for($company)->create([
        'name' => 'Rated Schema Board',
        'price_amount' => 500000,
        'price_currency' => 'XAF',
        'rating' => 4.8,
        'reviews_count' => 24,
    ]);

    $this->get(route('products.show', $rated->slug))
        ->assertOk()
        ->assertSee('"@type":"AggregateRating"', false)
        ->assertSee('"ratingValue":"4.8"', false)
        ->assertSee('"reviewCount":24', false);
});

it('renders every tab panel server-side so crawlers are not JS-gated', function () {
    $company = visibleSupplier();
    $product = Product::factory()->active()->for($company)->create([
        'description' => 'A long-form description that must appear in the served HTML.',
        'key_benefits' => ['Excellent strength and durability'],
        'certification' => 'Legal Origin Verified',
    ]);

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('role="tablist"', false)
        ->assertSee('aria-selected', false)
        ->assertSee('id="panel-description"', false)
        ->assertSee('id="panel-details"', false)
        ->assertSee('id="panel-shipping"', false)
        ->assertSee('id="panel-certifications"', false)
        ->assertSee('id="panel-reviews"', false)
        ->assertSee('A long-form description that must appear in the served HTML.')
        ->assertSee('Excellent strength and durability');
});

it('adds a product to the session RFQ list and feeds it into the quote form', function () {
    $company = visibleSupplier();
    $product = Product::factory()->active()->for($company)->create(['name' => 'Shortlisted Board']);

    $this->post(route('rfq-list.store', $product->slug), [], ['HTTP_REFERER' => route('products.show', $product->slug)])
        ->assertRedirect();

    expect(app(RfqList::class)->count())->toBe(1);

    // The quote form is now a wizard: its entry point seeds the basket from the
    // shortlist, and the line item surfaces once the buyer reaches step 2.
    $this->get(route('rfq.create'))->assertOk();
    $this->post(route('rfq.step.store', ['step' => 'details']), ['title' => 'Shortlisted board enquiry']);

    $this->get(route('rfq.step', ['step' => 'products']))
        ->assertOk()
        ->assertSee('Shortlisted Board');

    // Posting again toggles it back off.
    $this->post(route('rfq-list.store', $product->slug));
    expect(app(RfqList::class)->count())->toBe(0);
});

it('paginates the marketplace and reports real facet counts', function () {
    $company = visibleSupplier();

    Product::factory()->count(14)->active()->for($company)->create(['product_type' => ProductType::Logs]);
    Product::factory()->count(3)->active()->for($company)->create(['product_type' => ProductType::Veneer]);

    $this->get(route('marketplace'))
        ->assertOk()
        ->assertSee('17 Products Found')
        ->assertSee('"@type":"ItemList"', false)
        ->assertSee('"numberOfItems":17', false);

    // Facet counts are real query counts, not hard-coded numbers.
    $catalogue = app(ProductCatalogueService::class);
    $counts = collect($catalogue->typeFacets([]))->pluck('count', 'value');

    expect($counts[ProductType::Logs->value])->toBe(14)
        ->and($counts[ProductType::Veneer->value])->toBe(3);
});

/* ---------------------------------------------------------------------------
 | Mobile layout (< lg). Rendered server-side alongside the desktop layout.
 |-------------------------------------------------------------------------- */

it('renders the mobile-only blocks server-side rather than gating them behind JS', function () {
    $company = visibleSupplier(['city' => 'Yaoundé']);
    $product = Product::factory()->active()->for($company)->create([
        'name' => 'Mobile Iroko Board',
        'moq_quantity' => 20,
    ]);

    $html = $this->get(route('products.show', $product->slug))->assertOk()->getContent();

    // The two branches exist side by side; only CSS decides which is visible.
    expect($html)->toContain('class="lg:hidden"')
        ->toContain('class="hidden lg:block"')
        // Mobile-only landmarks.
        ->toContain('id="m-price-heading"')
        ->toContain('id="m-supplier-heading"')
        ->toContain('id="m-details-heading"')
        ->toContain('Minimum Order Quantity')
        ->toContain('View Company')
        ->toContain('Product Details');
});

it('shows the tagline only when the product records one', function () {
    $company = visibleSupplier();

    $with = Product::factory()->active()->for($company)->create([
        'tagline' => 'Premium African Hardwood – Export Quality',
    ]);
    $this->get(route('products.show', $with->slug))
        ->assertOk()
        ->assertSee('Premium African Hardwood – Export Quality', false);

    $without = Product::factory()->active()->for($company)->create(['tagline' => null]);
    $this->get(route('products.show', $without->slug))
        ->assertOk()
        ->assertDontSee('Premium African Hardwood');
});

it('renders the gallery Video tile only when a real video url is recorded', function () {
    $company = visibleSupplier();

    $none = Product::factory()->active()->for($company)->create(['video_url' => null]);
    $this->get(route('products.show', $none->slug))
        ->assertOk()
        ->assertDontSee('>Video<', false);

    $withVideo = Product::factory()->active()->for($company)->create([
        'video_url' => 'https://videos.example/iroko-mill.mp4',
    ]);
    $this->get(route('products.show', $withVideo->slug))
        ->assertOk()
        ->assertSee('https://videos.example/iroko-mill.mp4')
        ->assertSee('>Video<', false);
});

it('hides the indicative USD line unless an FX rate is configured', function () {
    $company = visibleSupplier();
    $product = Product::factory()->active()->for($company)->create([
        'price_amount' => 650000,
        'price_currency' => 'XAF',
    ]);

    config()->set('timber.fx.usd_per_xaf', null);
    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertDontSee('USD');

    config()->set('timber.fx.usd_per_xaf', 0.00166);
    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('USD 1,079')
        ->assertSee('indicative');
});

it('renders Chat with Supplier only when a public contact publishes whatsapp or a phone', function () {
    // No public contact number at all → the quote button stands alone.
    $silent = visibleSupplier();
    $silent->contacts()->update(['phone' => null, 'whatsapp' => null]);
    $quiet = Product::factory()->active()->for($silent)->create();

    $this->get(route('products.show', $quiet->slug))
        ->assertOk()
        ->assertSee('Request Quote')
        ->assertDontSee('Chat with Supplier')
        ->assertDontSee('Call Supplier')
        ->assertDontSee('wa.me');

    // A public WhatsApp number → a well-formed wa.me link.
    $chatty = visibleSupplier();
    $chatty->contacts()->update(['whatsapp' => '+237 6 99 00 00 00']);
    $loud = Product::factory()->active()->for($chatty)->create();

    $this->get(route('products.show', $loud->slug))
        ->assertOk()
        ->assertSee('Chat with Supplier')
        ->assertSee('https://wa.me/237699000000');

    // A PRIVATE contact is never used, even when it holds a number.
    $private = visibleSupplier();
    $private->contacts()->update(['is_public' => false, 'whatsapp' => '+237 6 11 11 11 11']);
    $hidden = Product::factory()->active()->for($private)->create();

    $this->get(route('products.show', $hidden->slug))
        ->assertOk()
        ->assertDontSee('wa.me');
});

it('renders each mobile trust badge only when its backing signal exists', function () {
    $bare = visibleSupplier(['response_rate_percent' => null, 'years_experience' => null]);
    $plain = Product::factory()->active()->for($bare)->create([
        'certification' => null,
        'grade' => null,
        'species_id' => null,
    ]);

    $this->get(route('products.show', $plain->slug))
        ->assertOk()
        ->assertDontSee('Sustainably Sourced')
        ->assertDontSee('Premium Quality')
        ->assertDontSee('Reliable Supply')
        ->assertDontSee('Export Ready');

    $strong = visibleSupplier(['response_rate_percent' => 94, 'years_experience' => 18]);
    $strong->exportMarkets()->create(['country_code' => 'FR']);
    $rich = Product::factory()->active()->for($strong)->create([
        'certification' => 'Legal Origin Verified',
        'grade' => 'Select & Better',
    ]);

    $this->get(route('products.show', $rich->slug))
        ->assertOk()
        ->assertSee('Sustainably Sourced')
        ->assertSee('Premium Quality')
        ->assertSee('Reliable Supply')
        ->assertSee('Export Ready');
});

it('renders the supplier stat strip from real counts, never rounded marketing numbers', function () {
    $company = visibleSupplier(['years_experience' => 18]);
    $company->exportMarkets()->createMany([
        ['country_code' => 'FR'], ['country_code' => 'NL'], ['country_code' => 'CN'],
    ]);

    $product = Product::factory()->active()->for($company)->create();
    Product::factory()->count(2)->active()->for($company)->create();

    $html = $this->get(route('products.show', $product->slug))->assertOk()->getContent();

    expect($html)->toContain('Export Countries')
        ->toContain('Years Experience')
        ->not->toContain('25+')
        ->not->toContain('15+')
        ->not->toContain('120+');

    // 3 real export markets, 3 real active products, 18 real years.
    $strip = str($html)->after('m-supplier-heading')->before('m-details-heading')->value();
    expect($strip)->toContain('>3</dd>')->toContain('>18</dd>');
});
