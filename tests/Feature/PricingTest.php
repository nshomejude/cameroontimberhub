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
