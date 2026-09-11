<?php

use App\Filament\Resources\Coupons\Pages\CreateCoupon;
use App\Models\Coupon;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function grantPricingManage(User $user): void
{
    // A role is required to pass User::canAccessPanel() at all; the
    // resource itself is gated purely on the `pricing.manage` permission
    // (granted directly here, per the M8 task brief, without touching
    // RolesAndPermissionsSeeder or FoundationTest's counts).
    $role = Role::firstOrCreate(['name' => 'finance_officer', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'pricing.manage', 'guard_name' => 'web']);
    $user->assignRole($role);
    $user->givePermissionTo('pricing.manage');
}

it('hides the Coupons resource from a staff user without pricing.manage', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/admin/coupons')->assertForbidden();
});

it('shows the Coupons resource to a user with pricing.manage', function () {
    $user = User::factory()->create();
    grantPricingManage($user);
    $this->actingAs($user);

    $this->get('/admin/coupons')->assertOk();
});

it('hides the Credits resource from a staff user without pricing.manage', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/admin/credits')->assertForbidden();
});

it('shows the Credits resource to a user with pricing.manage', function () {
    $user = User::factory()->create();
    grantPricingManage($user);
    $this->actingAs($user);

    $this->get('/admin/credits')->assertOk();
});

it('creates a coupon, storing the code uppercased and the percentage as a fraction', function () {
    $user = User::factory()->create();
    grantPricingManage($user);
    $this->actingAs($user);

    Livewire::test(CreateCoupon::class)
        ->fillForm([
            'code' => 'launch20',
            'type' => 'percent',
            'value' => 20,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $coupon = Coupon::firstWhere('code', 'LAUNCH20');

    expect($coupon)->not->toBeNull()
        ->and((string) $coupon->value)->toBe('0.2000');
});
