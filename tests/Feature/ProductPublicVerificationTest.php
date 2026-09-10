<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use App\Services\ProductQrCodeService;
use App\Support\ProductIdentifier;

it('assigns a CTH-CMR public id on create', function () {
    $product = Product::factory()
        ->for(Company::factory(), 'company')
        ->for(Species::factory(), 'species')
        ->create();

    expect($product->public_id)->toMatch('/^CTH-CMR-[A-Z]{3}-\d{5}$/');
});

it('uses XXX for a product with no species and is deterministic per row', function () {
    $product = Product::factory()->create(['species_id' => null]);

    expect($product->public_id)->toMatch('/^CTH-CMR-XXX-\d{5}$/');
    expect(ProductIdentifier::forProduct($product->fresh()))->toBe($product->public_id);
});

it('increments the per-letter-segment sequence', function () {
    $species = Species::factory()->create(['common_name' => 'Iroko']);

    $a = Product::factory()->for($species, 'species')->create();
    $b = Product::factory()->for($species, 'species')->create();

    expect($a->public_id)->toStartWith('CTH-CMR-IRO-');
    expect($b->public_id)->toStartWith('CTH-CMR-IRO-');
    expect($a->public_id)->not->toBe($b->public_id);
});

it('serves a public verification page for an active listing without leaking the numeric id', function () {
    $product = Product::factory()->active()
        ->for(Company::factory()->publiclyVisible(), 'company')
        ->create();

    $response = $this->get('/verify/product/'.$product->public_id)
        ->assertOk()
        ->assertSee($product->name)
        ->assertSee($product->company->legal_name)
        ->assertSee($product->public_id);

    expect($response->getContent())
        ->not->toContain('/marketplace/'.$product->getKey())
        ->and($response->getContent())->not->toContain('product_id');
});

it('404s an unknown public id and a draft listing without disclosing which', function () {
    $draft = Product::factory()->draft()->create();

    $this->get('/verify/product/'.$draft->public_id)->assertNotFound();
    $this->get('/verify/product/CTH-CMR-XXX-99999')->assertNotFound();
});

it('renders a QR SVG that encodes the verification URL', function () {
    $product = Product::factory()->active()
        ->for(Company::factory()->publiclyVisible(), 'company')
        ->create();

    $svg = app(ProductQrCodeService::class)->svg($product);

    expect($svg)->toContain('<svg')->toContain('</svg>');
});

it('backfills public ids idempotently', function () {
    $products = Product::factory()->count(3)->create();
    $before = $products->pluck('public_id', 'id');

    $this->artisan('products:backfill-public-ids')->assertExitCode(0);
    $this->artisan('products:backfill-public-ids')->assertExitCode(0);

    $after = Product::query()->whereIn('id', $before->keys())->pluck('public_id', 'id');

    expect($after->all())->toBe($before->all());
});
