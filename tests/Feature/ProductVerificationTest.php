<?php

use App\Enums\VerificationStage;
use App\Models\Product;
use App\Models\Verification;

it('gives a product a verification relation', function () {
    $product = Product::factory()->create();
    $verification = Verification::factory()->for($product, 'entity')->create();

    expect($product->fresh()->verification->is($verification))->toBeTrue();
});

it('reports a product with no verification yet as unverified', function () {
    $product = Product::factory()->create();

    expect($product->isVerified())->toBeFalse();
});

it('reports a product as verified once its verification reaches verified or published', function () {
    $product = Product::factory()->create();
    Verification::factory()->for($product, 'entity')->create(['stage' => VerificationStage::Verified]);

    expect($product->fresh()->isVerified())->toBeTrue();
});
