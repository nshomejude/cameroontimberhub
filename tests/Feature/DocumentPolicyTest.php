<?php

use App\Models\Document;
use App\Models\Species;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lets a verification officer view and delete any document', function () {
    $officer = staff('verification_officer');
    $document = Document::factory()->for(Species::factory()->create(), 'owner')->create();

    expect($officer->can('view', $document))->toBeTrue()
        ->and($officer->can('delete', $document))->toBeTrue()
        ->and($officer->can('approve', Document::class))->toBeTrue();
});

it('refuses a user with no relevant role or permission', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for(Species::factory()->create(), 'owner')->create();

    expect($user->can('view', $document))->toBeFalse()
        ->and($user->can('delete', $document))->toBeFalse()
        ->and($user->can('approve', Document::class))->toBeFalse();
});
