<?php

use App\Models\CompanyInquiry;
use App\Models\User;
use App\Policies\CompanyInquiryPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('grants viewAny/view only to users with inquiries.review', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $nobody = User::factory()->create();
    $policy = new CompanyInquiryPolicy;

    expect($policy->viewAny($admin))->toBeTrue()
        ->and($policy->viewAny($nobody))->toBeFalse();
});

it('super_admin has inquiries.review via the full permission set', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    expect($superAdmin->can('inquiries.review'))->toBeTrue();
});
