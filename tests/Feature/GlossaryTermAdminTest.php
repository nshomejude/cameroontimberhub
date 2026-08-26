<?php

use App\Filament\Resources\GlossaryTerms\Pages\CreateGlossaryTerm;
use App\Filament\Resources\GlossaryTerms\Pages\EditGlossaryTerm;
use App\Models\GlossaryTerm;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function glossaryStaff(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('renders the glossary resource pages for a content manager', function () {
    $term = GlossaryTerm::factory()->create();

    $this->actingAs(glossaryStaff('content_manager'));

    $this->get('/admin/glossary-terms')->assertOk();
    $this->get('/admin/glossary-terms/create')->assertOk();
    // GlossaryTerm's route key is its slug, so Filament binds by slug.
    $this->get('/admin/glossary-terms/'.$term->slug.'/edit')->assertOk();
});

it('creates a glossary term through the admin form and it goes live', function () {
    $this->actingAs(glossaryStaff('admin'));

    Livewire::test(CreateGlossaryTerm::class)
        ->fillForm([
            'term' => 'Demurrage',
            'slug' => 'demurrage',
            'definition' => 'A charge levied when a container is held beyond the free time allowed at the port.',
            'is_published' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $term = GlossaryTerm::where('slug', 'demurrage')->firstOrFail();

    expect($term->is_published)->toBeTrue();

    $this->get($term->url())->assertOk()->assertSee('Demurrage');
});

it('unpublishing a term through the admin form removes it from the public site', function () {
    $term = GlossaryTerm::factory()->create(['term' => 'Stumpage', 'slug' => 'stumpage']);

    $this->actingAs(glossaryStaff('admin'));

    Livewire::test(EditGlossaryTerm::class, ['record' => $term->slug])
        ->fillForm(['is_published' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->get(route('glossary.show', 'stumpage'))->assertNotFound();
    $this->get(route('glossary.index'))->assertOk()->assertDontSee('Stumpage');
});

it('denies the glossary resource to staff without the content permission', function () {
    $this->actingAs(glossaryStaff('verification_officer'));

    $this->get('/admin/glossary-terms')->assertForbidden();
});
