<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('serves the public home page', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee(__('messages.home.hero_title'));
    $response->assertSee('Cameroon Timber Hub');
});

it('redirects guests from the admin panel to the admin login', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('serves both panel login pages', function () {
    $this->get('/admin/login')->assertOk();
    $this->get('/dashboard/login')->assertOk();
});

it('seeds the canonical RBAC roles and permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Permission::count())->toBe(14);
    expect(Role::findByName('super_admin', 'web')->permissions)->toHaveCount(14);
    expect(Role::findByName('admin', 'web')->permissions)->toHaveCount(11);
    expect(Role::findByName('verification_officer', 'web')->permissions)->toHaveCount(6);
    expect(Role::findByName('content_manager', 'web')->permissions)->toHaveCount(3);
});

it('gates panel access by role and company membership', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    // Platform staff reach /admin but not the exporter dashboard (no company yet).
    expect($admin->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
    expect($admin->canAccessPanel(Filament::getPanel('exporter')))->toBeFalse();

    // A user with no role reaches neither panel.
    $nobody = User::factory()->create();
    expect($nobody->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});
