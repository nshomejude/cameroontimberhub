<?php

use App\Enums\TimberCategory;
use App\Livewire\SpeciesDirectory;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use Livewire\Livewire;

/**
 * Public /species directory — the faceted browse experience.
 *
 * Every assertion here is about REAL data: facet counts and product counts come
 * from queries, never from constants baked into the Blade.
 */
function directorySpecies(array $attributes = []): Species
{
    return Species::factory()->create($attributes);
}

it('renders the species directory index', function () {
    directorySpecies(['common_name' => 'Indexwood']);

    $this->get(route('species.index'))
        ->assertOk()
        ->assertSee('Timber Species Directory')
        ->assertSee('Indexwood');
});

it('lists only published species', function () {
    directorySpecies(['common_name' => 'Publicwood']);
    Species::factory()->unpublished()->create(['common_name' => 'Hiddenwood']);

    $this->get(route('species.index'))
        ->assertOk()
        ->assertSee('Publicwood')
        ->assertDontSee('Hiddenwood');
});

it('paginates the directory at the requested page size', function () {
    Species::factory()->count(15)->create();

    Livewire::test(SpeciesDirectory::class)
        ->assertViewHas('species', fn ($p) => $p->total() === 15 && $p->count() === 12)
        ->set('perPage', 24)
        ->assertViewHas('species', fn ($p) => $p->count() === 15);
});

it('narrows results by the category facet', function () {
    directorySpecies(['common_name' => 'Specialwood', 'commercial_category' => TimberCategory::Specialty]);
    directorySpecies(['common_name' => 'Primarywood', 'commercial_category' => TimberCategory::PrimaryHardwood]);

    Livewire::test(SpeciesDirectory::class)
        ->set('categories', [TimberCategory::Specialty->value])
        ->assertSee('Specialwood')
        ->assertDontSee('Primarywood');
});

it('narrows results by the property facet', function () {
    directorySpecies(['common_name' => 'Durablewood', 'durability_class' => 'Class 1 (Very Durable)']);
    directorySpecies(['common_name' => 'Perishwood', 'durability_class' => 'Class 5 (Not Durable)']);

    Livewire::test(SpeciesDirectory::class)
        ->set('properties', ['durable'])
        ->assertSee('Durablewood')
        ->assertDontSee('Perishwood');
});

it('narrows results by the application facet', function () {
    directorySpecies(['common_name' => 'Floorwood', 'typical_uses' => ['Industrial flooring']]);
    directorySpecies(['common_name' => 'Boxwood', 'typical_uses' => ['Packaging']]);

    Livewire::test(SpeciesDirectory::class)
        ->set('applications', ['flooring'])
        ->assertSee('Floorwood')
        ->assertDontSee('Boxwood');
});

it('narrows results by origin region', function () {
    directorySpecies(['common_name' => 'Eastwood', 'region_availability' => ['East']]);
    directorySpecies(['common_name' => 'Coastwood', 'region_availability' => ['Littoral']]);

    Livewire::test(SpeciesDirectory::class)
        ->set('region', 'East')
        ->assertSee('Eastwood')
        ->assertDontSee('Coastwood');
});

it('narrows results by the in-stock availability facet', function () {
    $stocked = directorySpecies(['common_name' => 'Stockedwood']);
    directorySpecies(['common_name' => 'Unstockedwood']);

    $company = Company::factory()->publiclyVisible()->create();
    Product::factory()->active()->for($company)->create(['species_id' => $stocked->id]);

    Livewire::test(SpeciesDirectory::class)
        ->set('inStock', true)
        ->assertSee('Stockedwood')
        ->assertDontSee('Unstockedwood');
});

it('ANDs facet groups together', function () {
    // Durable + flooring — only this one satisfies both groups.
    directorySpecies([
        'common_name' => 'Bothwood',
        'durability_class' => 'Class 1 (Very Durable)',
        'typical_uses' => ['Industrial flooring'],
    ]);
    // Durable, but not a flooring timber.
    directorySpecies([
        'common_name' => 'Durableonlywood',
        'durability_class' => 'Class 1 (Very Durable)',
        'typical_uses' => ['Packaging'],
    ]);
    // Flooring, but not durable.
    directorySpecies([
        'common_name' => 'Flooronlywood',
        'durability_class' => 'Class 5 (Not Durable)',
        'typical_uses' => ['Industrial flooring'],
    ]);

    Livewire::test(SpeciesDirectory::class)
        ->set('properties', ['durable'])
        ->set('applications', ['flooring'])
        ->assertSee('Bothwood')
        ->assertDontSee('Durableonlywood')
        ->assertDontSee('Flooronlywood');
});

it('searches common, scientific and trade names', function () {
    directorySpecies(['common_name' => 'Sapele', 'scientific_name' => 'Entandrophragma cylindricum', 'trade_names' => ['Aboudikro']]);
    directorySpecies(['common_name' => 'Ayous', 'scientific_name' => 'Triplochiton scleroxylon', 'trade_names' => ['Obeche']]);

    Livewire::test(SpeciesDirectory::class)
        ->set('search', 'Aboudikro')
        ->assertSee('Sapele')
        ->assertDontSee('Ayous');
});

it('round-trips filter state through the URL', function () {
    directorySpecies([
        'common_name' => 'Roundtripwood',
        'commercial_category' => TimberCategory::Specialty,
        'durability_class' => 'Class 1 (Very Durable)',
        'typical_uses' => ['Industrial flooring'],
        'region_availability' => ['East'],
    ]);
    directorySpecies(['common_name' => 'Othertimber', 'commercial_category' => TimberCategory::Softwood]);

    $url = route('species.index', [
        'category' => [TimberCategory::Specialty->value],
        'property' => ['durable'],
        'use' => ['flooring'],
        'region' => 'East',
        'sort' => 'name',
        'view' => 'list',
    ]);

    // The server-rendered page reflects the URL...
    $this->get($url)->assertOk()->assertSee('Roundtripwood')->assertDontSee('Othertimber');

    // ...and so does the Livewire component mounted from the same query string.
    Livewire::withQueryParams([
        'category' => [TimberCategory::Specialty->value],
        'property' => ['durable'],
        'use' => ['flooring'],
        'region' => 'East',
        'sort' => 'name',
        'view' => 'list',
    ])
        ->test(SpeciesDirectory::class)
        ->assertSet('categories', [TimberCategory::Specialty->value])
        ->assertSet('properties', ['durable'])
        ->assertSet('applications', ['flooring'])
        ->assertSet('region', 'East')
        ->assertSet('sort', 'name')
        ->assertSet('view', 'list')
        ->assertSee('Roundtripwood');
});

it('renders a real product count per species card', function () {
    $species = directorySpecies(['common_name' => 'Countedwood']);

    $visible = Company::factory()->publiclyVisible()->create();
    Product::factory()->count(3)->active()->for($visible)->create(['species_id' => $species->id]);

    // Neither a draft product nor one belonging to a hidden company counts.
    Product::factory()->for($visible)->create([
        'species_id' => $species->id,
        'status' => \App\Enums\ProductStatus::Draft,
    ]);
    $hidden = Company::factory()->create();
    Product::factory()->active()->for($hidden)->create(['species_id' => $species->id]);

    Livewire::test(SpeciesDirectory::class)
        ->assertViewHas('species', fn ($p) => $p->firstWhere('common_name', 'Countedwood')->products_count === 3)
        ->assertSee('Products');
});

it('drops a facet that has no backing data', function () {
    // Nothing in this fixture is CITES-heavy or marine-rated, so the water
    // facet must not be offered at all.
    directorySpecies(['common_name' => 'Plainwood', 'description' => 'A plain timber.', 'typical_uses' => ['Packaging']]);

    Livewire::test(SpeciesDirectory::class)
        ->assertViewHas('propertyFacets', fn (array $f) => ! collect($f)->contains('value', 'water-resistant'))
        ->assertViewHas('applicationFacets', fn (array $f) => ! collect($f)->contains('value', 'boat-building'));
});

it('emits ItemList and BreadcrumbList JSON-LD on the index', function () {
    directorySpecies(['common_name' => 'Schemalistwood', 'scientific_name' => 'Schemalistus woodus']);

    $response = $this->get(route('species.index'))->assertOk();

    $response->assertSee('"@type":"ItemList"', escape: false)
        ->assertSee('"@type":"BreadcrumbList"', escape: false)
        ->assertSee('"@type":"ListItem"', escape: false)
        ->assertSee('Schemalistwood');

    // The ItemList must be valid JSON and describe the visible page.
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $response->getContent(), $matches);

    $itemList = collect($matches[1])
        ->map(fn (string $json) => json_decode($json, true))
        ->firstWhere('@type', 'ItemList');

    expect($itemList)->not->toBeNull()
        ->and($itemList['numberOfItems'])->toBe(1)
        ->and($itemList['itemListElement'][0]['name'])->toBe('Schemalistwood')
        ->and($itemList['itemListElement'][0]['position'])->toBe(1);

    $breadcrumbs = collect($matches[1])
        ->map(fn (string $json) => json_decode($json, true))
        ->firstWhere('@type', 'BreadcrumbList');

    expect($breadcrumbs['itemListElement'])->toHaveCount(2)
        ->and($breadcrumbs['itemListElement'][1]['name'])->toBe('Timber Species');
});

it('marks the current page with aria-current', function () {
    Species::factory()->count(20)->create();

    $this->get(route('species.index'))->assertOk()->assertSee('aria-current="page"', escape: false);
});

it('offers a genuinely different list layout', function () {
    directorySpecies([
        'common_name' => 'Layoutwood',
        'janka_hardness' => 7200,
        'typical_uses' => ['Flooring'],
    ]);

    // The technical record ("Janka") only appears in the list row, never on the
    // grid card — proving the two layouts are not the same markup.
    Livewire::test(SpeciesDirectory::class)
        ->assertDontSee('Janka')
        ->call('setView', 'list')
        ->assertSee('Janka');
});

it('keeps the detail page classification, CITES badge and structured data', function () {
    $species = directorySpecies([
        'common_name' => 'Detailwood',
        'scientific_name' => 'Detailus woodus',
        'family' => 'Testaceae',
        'commercial_category' => TimberCategory::PrimaryHardwood,
        'density_kg_m3_min' => 500,
        'density_kg_m3_max' => 620,
        'durability_class' => 'Class 3 (Moderately Durable)',
        'typical_uses' => ['Boat building'],
        'region_availability' => ['Littoral'],
        'is_cites_listed' => true,
        'cites_appendix' => 'II',
    ]);

    $this->get(route('species.show', $species->slug))
        ->assertOk()
        ->assertSee('Classification')
        ->assertSee('Primary / Principal Hardwood')
        ->assertSee('500–620 kg/m³', escape: false)
        ->assertSee('Class 3 (Moderately Durable)')
        ->assertSee('Typical uses')
        ->assertSee('Boat building')
        ->assertSee('Littoral')
        ->assertSee('CITES status')
        ->assertSee('Appendix II')
        // Factual, non-legal-advice framing must survive the restyle.
        ->assertSee('not a regulatory or legal classification')
        ->assertSee('additionalProperty', escape: false)
        ->assertSee('"@type":"BreadcrumbList"', escape: false);
});

it('gives every species a swatch without reusing another species photograph', function () {
    $withPhoto = directorySpecies(['common_name' => 'Iroko']);
    $withoutPhoto = directorySpecies([
        'common_name' => 'Nophotowood',
        'characteristics' => ['Colour' => 'Jet black, occasionally with brown streaks'],
    ]);

    expect($withPhoto->swatch()['image'])->toBe('/img/species/iroko.png')
        ->and($withoutPhoto->swatch()['image'])->toBeNull()
        // Deterministic, and derived from this species' own recorded colour.
        ->and($withoutPhoto->swatch()['to'])->toBe('#17120f')
        ->and($withoutPhoto->swatch())->toBe($withoutPhoto->fresh()->swatch());
});
