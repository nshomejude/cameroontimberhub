<?php

use App\Models\Product;
use App\Models\User;
use App\Models\Verification;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lets a user with verification.review view and review any verification', function () {
    $officer = User::factory()->create();
    $officer->assignRole('verification_officer');
    $verification = Verification::factory()->for(Product::factory()->create(), 'entity')->create();

    expect($officer->can('view', $verification))->toBeTrue()
        ->and($officer->can('review', $verification))->toBeTrue();
});

it('refuses a user with no relevant permission', function () {
    $user = User::factory()->create();
    $verification = Verification::factory()->for(Product::factory()->create(), 'entity')->create();

    expect($user->can('view', $verification))->toBeFalse()
        ->and($user->can('review', $verification))->toBeFalse();
});
