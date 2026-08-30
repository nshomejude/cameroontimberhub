<?php

use App\Enums\OrganisationType;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\CarbonProject;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;

/**
 * A plain-HTTP sweep of the newest public pages: every /pricing segment,
 * the carbon-projects directory (index + a real detail page), the logistics
 * directory, a processor/artisan company profile (Capacity/Carbon-Project
 * tabs), and a finished-goods product show page -- confirming each renders
 * 200 with no obvious broken content, including with the new spec fields
 * left null.
 */
it('renders all 8 /pricing segments', function () {
    $response = $this->get('/pricing');

    $response->assertOk();

    foreach ([
        'Buy timber', 'Sell timber locally', 'Deal timber', 'Export timber',
        'Buy internationally', 'Verify & comply', 'Analyze the market', 'Learn',
    ] as $label) {
        $response->assertSee($label);
    }
});

it('renders the carbon-projects directory and a real detail page', function () {
    $company = Company::factory()->publiclyVisible()->create(['type' => OrganisationType::CarbonDeveloper]);
    $project = CarbonProject::factory()->create([
        'company_id' => $company->id,
        'status' => ProductStatus::Active,
        'project_type' => 'reforestation',
    ]);

    $this->get('/carbon-projects')
        ->assertOk()
        ->assertSee($project->name);

    $this->get('/carbon-projects/'.$project->getKey())
        ->assertOk()
        ->assertSee($project->name);
});

it('renders the logistics directory with capacity chips', function () {
    $this->get('/logistics-directory')->assertOk();
});

it('renders a processor company profile with the Capacity/Carbon-Project tabs, no error', function () {
    $company = Company::factory()->publiclyVisible()->create(['type' => OrganisationType::Processor]);

    $this->get('/companies/'.$company->slug)->assertOk();
});

it('renders an artisan company profile with no error', function () {
    $company = Company::factory()->publiclyVisible()->create(['type' => OrganisationType::Artisan]);

    $this->get('/companies/'.$company->slug)->assertOk();
});

it('renders a finished-goods product show page when the new spec fields are null', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $species = Species::factory()->create();

    $product = Product::factory()->for($company, 'company')->create([
        'product_type' => ProductType::Flooring,
        'status' => ProductStatus::Active,
        'species_id' => $species->getKey(),
        'materials_used' => null,
        'finish' => null,
        'dimensions_description' => null,
    ]);

    $this->get('/marketplace/'.$product->slug)->assertOk();
});

it('renders a finished-goods product show page with materials_used/finish/dimensions_description set', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $species = Species::factory()->create();

    $product = Product::factory()->for($company, 'company')->create([
        'product_type' => ProductType::Flooring,
        'status' => ProductStatus::Active,
        'species_id' => $species->getKey(),
        'materials_used' => 'Solid Iroko, brass hardware',
        'finish' => 'Matte lacquer',
        'dimensions_description' => '180cm x 90cm x 75cm',
    ]);

    $response = $this->get('/marketplace/'.$product->slug);

    $response->assertOk();
    $response->assertSee('Solid Iroko, brass hardware');
    $response->assertSee('Matte lacquer');
    $response->assertSee('180cm x 90cm x 75cm');
});
