<?php

use App\Models\DeviceToken;
use App\Models\User;

it('registers a device token idempotently on the token itself', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/devices', [
            'expo_push_token' => 'ExponentPushToken-abc123',
            'platform' => 'android',
            'device_name' => 'Pixel 8',
        ])
        ->assertCreated();

    expect(DeviceToken::where('expo_push_token', 'ExponentPushToken-abc123')->count())->toBe(1);

    // Re-registering the SAME token (same user, different device name) updates
    // the row in place rather than creating a duplicate.
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/devices', [
            'expo_push_token' => 'ExponentPushToken-abc123',
            'platform' => 'android',
            'device_name' => 'Pixel 8 Pro',
        ])
        ->assertCreated();

    expect(DeviceToken::where('expo_push_token', 'ExponentPushToken-abc123')->count())->toBe(1);
    expect(DeviceToken::where('expo_push_token', 'ExponentPushToken-abc123')->first()->device_name)->toBe('Pixel 8 Pro');
});

it('re-points a token to a different user on re-registration (logout/login as someone else)', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $this->actingAs($first, 'sanctum')
        ->postJson('/api/v1/devices', [
            'expo_push_token' => 'ExponentPushToken-shared-device',
            'platform' => 'ios',
        ])
        ->assertCreated();

    $this->actingAs($second, 'sanctum')
        ->postJson('/api/v1/devices', [
            'expo_push_token' => 'ExponentPushToken-shared-device',
            'platform' => 'ios',
        ])
        ->assertCreated();

    $token = DeviceToken::where('expo_push_token', 'ExponentPushToken-shared-device')->firstOrFail();

    expect((int) $token->user_id)->toBe($second->getKey());
    expect(DeviceToken::where('expo_push_token', 'ExponentPushToken-shared-device')->count())->toBe(1);
});

it('rejects an unknown platform value', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/devices', [
            'expo_push_token' => 'ExponentPushToken-xyz',
            'platform' => 'windows',
        ])
        ->assertStatus(422);
});

it('deletes only the caller\'s own device token; 404/no-op for someone else\'s', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $token = DeviceToken::factory()->create(['user_id' => $owner->getKey(), 'expo_push_token' => 'ExponentPushToken-mine']);

    $this->actingAs($stranger, 'sanctum')
        ->deleteJson('/api/v1/devices/ExponentPushToken-mine')
        ->assertNotFound();

    expect(DeviceToken::whereKey($token->getKey())->exists())->toBeTrue();

    $this->actingAs($owner, 'sanctum')
        ->deleteJson('/api/v1/devices/ExponentPushToken-mine')
        ->assertOk();

    expect(DeviceToken::whereKey($token->getKey())->exists())->toBeFalse();
});

it('no-ops deleting a token that does not exist at all', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/v1/devices/ExponentPushToken-nonexistent')
        ->assertNotFound();
});
