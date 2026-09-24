<?php

use App\Models\NotificationPreference;
use App\Models\User;

it('auto-creates default preferences on first read', function () {
    $user = User::factory()->create();

    expect(NotificationPreference::where('user_id', $user->getKey())->exists())->toBeFalse();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/notifications/preferences')
        ->assertOk()
        ->assertJsonPath('data.channels.push', true)
        ->assertJsonPath('data.channels.email', true)
        ->assertJsonPath('data.types.quote_received', true)
        ->assertJsonPath('data.types.order_status_changed', true)
        ->assertJsonPath('data.types.message_received', true)
        ->assertJsonPath('data.types.dispute_reply', true);

    expect(NotificationPreference::where('user_id', $user->getKey())->exists())->toBeTrue();
});

it('partially updates only the given keys, leaving the rest untouched', function () {
    $user = User::factory()->create();
    NotificationPreference::forUser($user);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/v1/notifications/preferences', [
            'types' => ['quote_received' => false],
        ])
        ->assertOk()
        ->assertJsonPath('data.types.quote_received', false)
        ->assertJsonPath('data.types.order_status_changed', true)
        ->assertJsonPath('data.channels.push', true);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/v1/notifications/preferences', [
            'channels' => ['push' => false],
        ])
        ->assertOk()
        ->assertJsonPath('data.channels.push', false)
        ->assertJsonPath('data.channels.email', true)
        // Still off from the previous PATCH — untouched by this one.
        ->assertJsonPath('data.types.quote_received', false);
});

it('rejects an unknown preference key with 422', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/v1/notifications/preferences', [
            'types' => ['not_a_real_type' => false],
        ])
        ->assertStatus(422);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/v1/notifications/preferences', [
            'channels' => ['sms' => false],
        ])
        ->assertStatus(422);
});
