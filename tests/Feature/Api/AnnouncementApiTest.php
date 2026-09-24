<?php

use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Models\Announcement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('returns active announcements ordered by sort_order', function () {
    Announcement::factory()->create(['title' => 'Second', 'sort_order' => 2]);
    Announcement::factory()->create(['title' => 'First', 'sort_order' => 1]);

    $response = $this->getJson('/api/v1/announcements')->assertOk();

    expect(collect($response->json('data'))->pluck('title')->all())->toBe(['First', 'Second']);
});

it('excludes inactive, not-yet-started and expired announcements', function () {
    Announcement::factory()->create(['title' => 'Inactive', 'is_active' => false]);
    Announcement::factory()->create(['title' => 'Future', 'starts_at' => now()->addDay()]);
    Announcement::factory()->create(['title' => 'Expired', 'ends_at' => now()->subDay()]);
    Announcement::factory()->create(['title' => 'Live']);

    $response = $this->getJson('/api/v1/announcements')->assertOk();

    expect(collect($response->json('data'))->pluck('title')->all())->toBe(['Live']);
});

it('returns an empty data array rather than an error when none are active', function () {
    $this->getJson('/api/v1/announcements')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('shapes each announcement with the documented fields', function () {
    Announcement::factory()->create([
        'title' => 'Harvest promo',
        'body' => 'Details here',
        'cta_label' => 'See offers',
        'cta_screen' => '047',
        'cta_reference' => 'promo-1',
    ]);

    $this->getJson('/api/v1/announcements')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'title', 'body', 'image_url', 'cta_label', 'cta_screen', 'cta_reference', 'starts_at', 'ends_at']],
        ])
        ->assertJsonPath('data.0.cta_screen', '047');
});

it('gates the Filament announcement resource behind pages.manage', function () {
    $manager = User::factory()->create();
    $manager->assignRole('content_manager'); // has pages.manage per RolesAndPermissionsSeeder

    $other = User::factory()->create();
    $other->assignRole('verification_officer'); // does not have pages.manage

    $this->actingAs($manager);
    expect(AnnouncementResource::canViewAny())->toBeTrue();

    $this->actingAs($other);
    expect(AnnouncementResource::canViewAny())->toBeFalse();
});
