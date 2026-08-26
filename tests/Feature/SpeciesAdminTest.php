<?php

use App\Filament\Resources\Species\Pages\CreateSpecies;
use App\Filament\Resources\Species\Pages\EditSpecies;
use App\Models\Species;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function speciesStaff(string $role = 'admin'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('renders the species resource pages for a content manager', function () {
    $species = Species::factory()->create();

    $this->actingAs(speciesStaff('content_manager'));

    $this->get('/admin/species')->assertOk();
    $this->get('/admin/species/create')->assertOk();
    $this->get('/admin/species/'.$species->slug.'/edit')->assertOk();
});

// Regression: the authoritative_sources repeater must not default to one blank
// item. Filament's Repeater defaults to defaultItems(1), which renders a blank
// row on the create form whose four inner fields are all required — making it
// impossible to add a species without inventing a citation, exactly what this
// field exists to prevent.
it('creates a species without supplying any authoritative source', function () {
    $this->actingAs(speciesStaff('admin'));

    Livewire::test(CreateSpecies::class)
        ->fillForm(['common_name' => 'Movingui'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Species::where('common_name', 'Movingui')->firstOrFail()->authoritative_sources)->toBeEmpty();
});

it('saves an unrelated edit to a species that has no authoritative sources', function () {
    $species = Species::factory()->create([
        'common_name' => 'Before',
        'authoritative_sources' => null,
    ]);

    $this->actingAs(speciesStaff('admin'));

    Livewire::test(EditSpecies::class, ['record' => $species->slug])
        ->fillForm(['common_name' => 'After'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($species->refresh()->common_name)->toBe('After')
        // Filament normalises an untouched empty repeater to [], never a fabricated row.
        ->and($species->authoritative_sources)->toBeEmpty();
});

it('accepts taxonomy and the other knowledge-system fields through the admin form', function () {
    $species = Species::factory()->create(['authoritative_sources' => null]);

    $this->actingAs(speciesStaff('admin'));

    Livewire::test(EditSpecies::class, ['record' => $species->slug])
        ->fillForm([
            'taxonomy' => ['family' => 'Moraceae', 'genus' => 'Milicia'],
            'french_name' => 'Iroko',
            'workability' => 'Works well with hand and machine tools.',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($species->refresh()->taxonomy)->toEqual(['family' => 'Moraceae', 'genus' => 'Milicia'])
        ->and($species->french_name)->toBe('Iroko');
});
