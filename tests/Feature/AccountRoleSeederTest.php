<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

it('seeds all 7 account-capability roles from brief section 3.1, distinct from the staff panel roles', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $accountRoles = ['buyer', 'supplier', 'processor', 'artisan', 'carbon_developer', 'carbon_buyer', 'logistics_partner'];

    foreach ($accountRoles as $role) {
        expect(Role::where('name', $role)->where('guard_name', 'web')->exists())->toBeTrue("Missing account role: {$role}");
    }

    // Staff roles remain untouched and distinct.
    expect(Role::where('name', 'admin')->exists())->toBeTrue()
        ->and(Role::where('name', 'verification_officer')->exists())->toBeTrue();
});

it('lets one user hold both the buyer and supplier account roles at once', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create();

    $user->assignRole('buyer', 'supplier');

    expect($user->fresh()->hasRole('buyer'))->toBeTrue()
        ->and($user->fresh()->hasRole('supplier'))->toBeTrue();
});
