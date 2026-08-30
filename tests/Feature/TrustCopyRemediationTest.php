<?php

use App\Models\Company;
use Database\Seeders\PageSeeder;

it('shows the CTH-branded verified badge and a link to how verification works, not the bare overclaiming badge', function () {
    $company = Company::factory()->publiclyVisible()->create();

    $response = $this->get(route('companies.show', $company->slug));

    $response->assertOk();
    $response->assertSee('CTH Verified Supplier');
    $response->assertDontSee('>Verified Supplier<', false);
    $response->assertSee('How verification works');
    $response->assertSee(route('verification.info'), false);
});

it('qualifies the self-reported on-time delivery figure as supplier-reported, not an independently verified fact', function () {
    $company = Company::factory()->publiclyVisible()->create([
        'on_time_delivery_percent' => 98,
    ]);

    $response = $this->get(route('companies.show', $company->slug));

    $response->assertOk();
    $response->assertSee('98%');
    $response->assertSee('self-reported');
});

it('renders the verification methodology page with accurate, scope-limited content', function () {
    $this->seed(PageSeeder::class);

    $response = $this->get(route('verification.info'));

    $response->assertOk();
    $response->assertSee('How we verify timber exporters');
    // What CTH actually does: manual document review of registration/export/compliance docs.
    $response->assertSee('document review');
    $response->assertSee('business registration');
    // What it explicitly is not: a guarantee of the company's standing/conduct.
    $response->assertSee('does not constitute a guarantee');
    $response->assertDontSee('Government approved');
    $response->assertDontSee('EUDR Compliant');
    $response->assertDontSee('Legally Guaranteed');
});
