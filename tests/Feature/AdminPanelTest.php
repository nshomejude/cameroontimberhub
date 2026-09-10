<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

// staff() lives in tests/Support/staff_helper.php (autoload-dev.files) —
// DocumentPolicyTest reuses it and --parallel puts the two files in
// different workers.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('renders the species resource pages for a content manager', function () {
    $this->actingAs(staff('content_manager'));

    $this->get('/admin/species')->assertOk();
    $this->get('/admin/species/create')->assertOk();
});

it('renders the company resource list and edit for an admin', function () {
    $company = Company::factory()->publiclyVisible()->create();

    $this->actingAs(staff('admin'));

    $this->get('/admin/companies')->assertOk();
    // Company's route key is its slug (set for public URLs), so Filament binds by slug.
    $this->get('/admin/companies/'.$company->slug.'/edit')->assertOk();
});

it('renders the users resource list and edit for a super admin', function () {
    $target = User::factory()->create(['name' => 'Target Person']);

    $this->actingAs(staff('super_admin'));

    $this->get('/admin/users')->assertOk()->assertSee('Target Person');
    $this->get('/admin/users/'.$target->getKey().'/edit')->assertOk();
});

it('saves a user edit through the resource form without touching non-existent columns', function () {
    $target = User::factory()->create(['name' => 'Before', 'email' => 'before@example.test']);

    Livewire\Livewire::actingAs(staff('super_admin'))
        ->test(App\Filament\Resources\Users\Pages\EditUser::class, ['record' => $target->getKey()])
        ->fillForm(['name' => 'After', 'email' => 'after@example.test'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh())
        ->name->toBe('After')
        ->email->toBe('after@example.test');
});

it('hides the users resource from a non-super-admin', function () {
    $this->actingAs(staff('admin'));

    $this->get('/admin/users')->assertForbidden();
});

it('hides species management from a verification officer', function () {
    $this->actingAs(staff('verification_officer'));

    $this->get('/admin/species')->assertForbidden();
});

it('blocks the admin panel for a user with no platform role', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin')->assertForbidden();
});
