<?php

use App\Models\Company;
use App\Models\Plan;

// The header nav also contains an unrelated "Verified Suppliers" trust
// blurb, so assertions target the badge's own markup rather than the
// substring "Verified Supplier" (which "Verified Suppliers" also contains).
const BADGE_MARKUP = 'bg-forest-600 px-3 py-1';

it('does not render the verified badge for a Free-plan company even with a verification badge row', function () {
    $plan = Plan::factory()->create(['features' => ['verified_badge' => false]]);
    $company = Company::factory()->publiclyVisible()->create(['plan_id' => $plan->id]);

    $response = $this->get(route('companies.show', $company->slug));

    $response->assertOk();
    $response->assertDontSee(BADGE_MARKUP, false);
});

it('renders the verified badge for an Enterprise-plan company with a verification badge row', function () {
    $plan = Plan::factory()->create(['features' => ['verified_badge' => true]]);
    $company = Company::factory()->publiclyVisible()->create(['plan_id' => $plan->id]);

    $response = $this->get(route('companies.show', $company->slug));

    $response->assertOk();
    $response->assertSee(BADGE_MARKUP, false);
});

it('does not render the verified badge for a company with no plan at all', function () {
    $company = Company::factory()->publiclyVisible()->create(['plan_id' => null]);

    $response = $this->get(route('companies.show', $company->slug));

    $response->assertOk();
    $response->assertDontSee(BADGE_MARKUP, false);
});
