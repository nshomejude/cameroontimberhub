<?php

use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('shows a mismatch warning for a featured company whose plan lacks the featured entitlement', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $plan = Plan::factory()->create(['features' => ['featured' => false]]);
    $company = Company::factory()->create(['plan_id' => $plan->id, 'is_featured' => true, 'legal_name' => 'Mismatch Co']);

    $this->actingAs($admin);

    Livewire::test(ListCompanies::class)
        ->assertSee('Mismatch Co')
        ->assertSee('Featured without plan');
});

it('shows no mismatch warning when a featured company plan includes the featured entitlement', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $plan = Plan::factory()->create(['features' => ['featured' => true]]);
    Company::factory()->create(['plan_id' => $plan->id, 'is_featured' => true, 'legal_name' => 'Consistent Co']);

    $this->actingAs($admin);

    Livewire::test(ListCompanies::class)
        ->assertSee('Consistent Co')
        ->assertDontSee('Featured without plan');
});
