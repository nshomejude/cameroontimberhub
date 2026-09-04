<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('never advertises more suppliers or products than really exist', function () {
    // Regression guard. The homepage counters previously used the mockup's
    // figures as a floor via max($actual, $floor), so the hero advertised
    // "200+ Verified Suppliers" and "5,000+ Timber Products" on a platform
    // that had 13 and 9 -- a false claim to buyers on the most public page.
    $response = $this->get('/')->assertOk();

    foreach (['200+', '5,000+', '1,200+', '50+'] as $inflated) {
        $response->assertDontSee($inflated);
    }
});

it('serves the public home page', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee(__('messages.home.hero_eyebrow'));
    $response->assertSee(__('messages.home.hero_subtitle'));
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

    expect(Permission::count())->toBe(19);
    expect(Role::findByName('super_admin', 'web')->permissions)->toHaveCount(19);
    expect(Role::findByName('admin', 'web')->permissions)->toHaveCount(16);
    expect(Role::findByName('verification_officer', 'web')->permissions)->toHaveCount(7);
    expect(Role::findByName('content_manager', 'web')->permissions)->toHaveCount(3);
    // Admin governance segregation of duties (blueprint §88, §89).
    expect(Role::findByName('compliance_officer', 'web')->permissions)->toHaveCount(3);
    expect(Role::findByName('billing_officer', 'web')->permissions)->toHaveCount(3);
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
