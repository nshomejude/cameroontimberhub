<?php

use App\Enums\VerificationStage;
use App\Models\Company;
use App\Models\Verification;
use Database\Seeders\PageSeeder;

it('shows the CTH-branded verified badge and a link to how verification works, not the bare overclaiming badge', function () {
    $company = Company::factory()->publiclyVisible()->create();
    // The badge now also requires verification_tier > 0, which is hard-capped
    // to 0 unless isVerified() (the real Verification workflow) is true.
    Verification::factory()->create([
        'entity_type' => Company::class,
        'entity_id' => $company->id,
        'stage' => VerificationStage::Verified,
    ]);
    $company->unsetRelation('verification');
    $company->verification_tier = \App\Enums\VerificationTier::IdentityVerified;
    $company->save();

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
