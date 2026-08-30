<?php

use App\Models\Plan;
use Database\Seeders\PlanSeeder;

it('renders the pricing page with all 8 segment headings', function () {
    (new PlanSeeder)->run();

    $response = $this->get('/pricing');

    $response->assertOk();
    $response->assertSee('Buy timber');
    $response->assertSee('Sell timber locally');
    $response->assertSee('Deal timber');
    $response->assertSee('Export timber');
    $response->assertSee('Buy internationally');
    $response->assertSee('Verify &amp; comply', false);
    $response->assertSee('Analyze the market');
    $response->assertSee('Learn');
});

it('renders the buy segment from real Plan data in the database', function () {
    (new PlanSeeder)->run();

    $response = $this->get('/pricing');

    $response->assertOk();
    $response->assertSee('Buyer Plus');
    $response->assertSee('Business Buyer');

    $plan = Plan::where('slug', 'buyer-plus')->first();
    expect($plan)->not->toBeNull();
    expect($plan->segment)->toBe('buy');
});

it('scopes plans to the right segment via Plan::forSegment', function () {
    (new PlanSeeder)->run();

    $sellPlans = Plan::forSegment('sell')->pluck('slug')->all();
    $buyPlans = Plan::forSegment('buy')->pluck('slug')->all();

    expect($sellPlans)->toEqualCanonicalizing(['free', 'professional', 'enterprise']);
    expect($buyPlans)->toEqualCanonicalizing(['buyer-free', 'buyer-plus', 'business-buyer', 'corporate-buyer']);
});

it('leaves the other static segments unaffected', function () {
    (new PlanSeeder)->run();

    $response = $this->get('/pricing');

    $response->assertOk();
    $response->assertSee('Exporter Professional');
});

it('lets an admin edit a buy-segment plan like any other plan', function () {
    (new PlanSeeder)->run();

    $plan = Plan::where('slug', 'buyer-plus')->first();

    expect($plan->segment)->toBe('buy');

    $plan->update(['name' => 'Buyer Plus Updated']);

    expect($plan->fresh()->name)->toBe('Buyer Plus Updated');
});

it('renders all 6 newly-converted segments from real Plan data in the database', function () {
    (new PlanSeeder)->run();

    $response = $this->get('/pricing');

    $response->assertOk();
    $response->assertSee('Dealer Pro');
    $response->assertSee('Exporter Business');
    $response->assertSee('Buyer Professional');
    $response->assertSee('Verified Exporter');
    $response->assertSee('Market Intelligence Professional');
    $response->assertSee('Short course');
});

it('scopes plans to the right segment via Plan::forSegment for all 8 segment values', function () {
    (new PlanSeeder)->run();

    expect(Plan::forSegment('deal')->pluck('slug')->all())
        ->toEqualCanonicalizing(['dealer-free', 'dealer-pro', 'dealer-network']);
    expect(Plan::forSegment('export')->pluck('slug')->all())
        ->toEqualCanonicalizing(['exporter-professional', 'exporter-business', 'exporter-enterprise']);
    expect(Plan::forSegment('buy-international')->pluck('slug')->all())
        ->toEqualCanonicalizing(['international-buyer-free', 'buyer-professional', 'buyer-enterprise']);
    expect(Plan::forSegment('verify-comply')->pluck('slug')->all())
        ->toEqualCanonicalizing(['verified-timber-supplier', 'verified-exporter', 'compliance-professional', 'traceability-professional']);
    expect(Plan::forSegment('analyze')->pluck('slug')->all())
        ->toEqualCanonicalizing(['market-data-free', 'market-intelligence-professional', 'market-intelligence-enterprise']);
    expect(Plan::forSegment('learn')->pluck('slug')->all())
        ->toEqualCanonicalizing(['knowledge-centre', 'short-course', 'professional-certificate']);
});

it('changing a Plan row changes what the pricing page shows for a newly-converted segment', function () {
    (new PlanSeeder)->run();

    Plan::create([
        'slug' => 'dealer-custom-test',
        'name' => 'Dealer Custom Test Tier',
        'description' => 'A custom tier created for this test.',
        'price_amount' => 12345,
        'price_currency' => 'XAF',
        'billing_period' => 'monthly',
        'sort_order' => 99,
        'segment' => 'deal',
        'is_active' => true,
        'features' => ['bullets' => ['Custom bullet for this test']],
    ]);

    $response = $this->get('/pricing');

    $response->assertOk();
    $response->assertSee('Dealer Custom Test Tier');
    $response->assertSee('Custom bullet for this test');
});
