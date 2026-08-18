<?php

use App\Enums\LogExportStatus;
use App\Enums\ProductType;
use App\Enums\TimberCategory;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use Database\Seeders\SpeciesSeeder;

it('seeds the full Cameroon species catalogue', function () {
    $this->seed(SpeciesSeeder::class);

    expect(Species::count())->toBe(52);

    // The species the ProductSeeder joins to by slug must keep existing.
    foreach (['iroko', 'sapele', 'tali', 'azobe', 'padouk', 'ayous'] as $slug) {
        expect(Species::where('slug', $slug)->exists())->toBeTrue("missing species: {$slug}");
    }
});

it('is idempotent — re-running the seeder does not duplicate species', function () {
    $this->seed(SpeciesSeeder::class);
    $first = Species::count();

    $this->seed(SpeciesSeeder::class);

    expect(Species::count())->toBe($first)
        ->and(Species::distinct()->count('slug'))->toBe($first);
});

it('leaves log export status unverified for every seeded species', function () {
    $this->seed(SpeciesSeeder::class);

    // Regulatory status is a placeholder — the seeder must never assert one.
    expect(Species::where('log_export_status', '!=', LogExportStatus::Unknown->value)->count())->toBe(0);
});

it('persists classification fields and casts them to enums', function () {
    $species = Species::factory()->create([
        'common_name' => 'Castwood',
        'commercial_category' => TimberCategory::PromotedSpecies,
        'log_export_status' => LogExportStatus::Restricted,
        'is_promoted' => true,
        'density_kg_m3_min' => 600,
        'density_kg_m3_max' => 750,
        'durability_class' => 'Class 2 (Durable)',
        'janka_hardness' => 6400,
        'typical_uses' => ['Flooring', 'Joinery'],
        'region_availability' => ['East', 'South'],
    ]);

    $fresh = $species->fresh();

    expect($fresh->commercial_category)->toBe(TimberCategory::PromotedSpecies)
        ->and($fresh->log_export_status)->toBe(LogExportStatus::Restricted)
        ->and($fresh->is_promoted)->toBeTrue()
        ->and($fresh->typical_uses)->toBe(['Flooring', 'Joinery'])
        ->and($fresh->region_availability)->toBe(['East', 'South'])
        ->and($fresh->janka_hardness)->toBe(6400)
        ->and($fresh->densityRange())->toBe('600–750 kg/m³');
});

it('narrows the public species index by commercial category', function () {
    Species::factory()->create([
        'common_name' => 'Primarywood',
        'commercial_category' => TimberCategory::PrimaryHardwood,
    ]);
    Species::factory()->create([
        'common_name' => 'Specialwood',
        'commercial_category' => TimberCategory::Specialty,
    ]);

    $this->get(route('species.index'))
        ->assertOk()
        ->assertSee('Primarywood')
        ->assertSee('Specialwood');

    $this->get(route('species.index', ['category' => TimberCategory::PrimaryHardwood->value]))
        ->assertOk()
        ->assertSee('Primarywood')
        ->assertDontSee('Specialwood');
});

it('narrows the public species index with the promoted toggle', function () {
    Species::factory()->create([
        'common_name' => 'Promotedwood',
        'commercial_category' => TimberCategory::PromotedSpecies,
        'is_promoted' => true,
    ]);
    Species::factory()->create([
        'common_name' => 'Regularwood',
        'commercial_category' => TimberCategory::PrimaryHardwood,
        'is_promoted' => false,
    ]);

    $this->get(route('species.index', ['promoted' => 1]))
        ->assertOk()
        ->assertSee('Promotedwood')
        ->assertDontSee('Regularwood');
});

it('emits ItemList JSON-LD on the species index', function () {
    Species::factory()->create(['common_name' => 'Listedwood']);

    $this->get(route('species.index'))
        ->assertOk()
        ->assertSee('ItemList', escape: false)
        ->assertSee('Listedwood');
});

it('renders the classification block and JSON-LD on the species detail page', function () {
    $species = Species::factory()->create([
        'common_name' => 'Schemawood',
        'scientific_name' => 'Schemus woodus',
        'family' => 'Testaceae',
        'trade_names' => ['Schemawood Trade Name'],
        'commercial_category' => TimberCategory::PrimaryHardwood,
        'density_kg_m3_min' => 500,
        'density_kg_m3_max' => 620,
        'durability_class' => 'Class 3 (Moderately Durable)',
        'typical_uses' => ['Boat building'],
        'region_availability' => ['Littoral'],
    ]);

    $response = $this->get(route('species.show', $species->slug))->assertOk();

    $response
        // Visible classification block.
        ->assertSee('Classification')
        ->assertSee('Primary / Principal Hardwood')
        ->assertSee('500–620 kg/m³', escape: false)
        ->assertSee('Class 3 (Moderately Durable)')
        ->assertSee('Typical uses')
        ->assertSee('Boat building')
        ->assertSee('Littoral')
        // JSON-LD.
        ->assertSee('additionalProperty', escape: false)
        ->assertSee('Schemawood Trade Name', escape: false);
});

it('shows a neutral CITES badge without legal advice', function () {
    $species = Species::factory()->create([
        'common_name' => 'Citeswood',
        'is_cites_listed' => true,
        'cites_appendix' => 'II',
    ]);

    $this->get(route('species.show', $species->slug))
        ->assertOk()
        ->assertSee('CITES status')
        ->assertSee('Appendix II');
});

it('accepts a product for every ProductType case', function () {
    $company = Company::factory()->publiclyVisible()->create();

    // Proves the products_type_check CHECK constraint was widened by the migration.
    foreach (ProductType::cases() as $type) {
        $product = Product::factory()->active()->for($company)->create([
            'name' => 'Constraint probe '.$type->value,
            'product_type' => $type,
        ]);

        expect($product->fresh()->product_type)->toBe($type);
    }

    expect(Product::count())->toBe(count(ProductType::cases()));
});

it('gives every ProductType case a label and a description', function () {
    foreach (ProductType::cases() as $type) {
        expect($type->label())->not->toBe('')
            ->and($type->description())->not->toBe('')
            ->and($type->color())->not->toBe('');
    }
});
