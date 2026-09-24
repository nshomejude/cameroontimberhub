<?php

namespace Database\Factories;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeviceToken> */
class DeviceTokenFactory extends Factory
{
    protected $model = DeviceToken::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'expo_push_token' => 'ExponentPushToken['.$this->faker->uuid().']',
            'platform' => $this->faker->randomElement(['android', 'ios']),
            'device_name' => $this->faker->word(),
            'last_seen_at' => now(),
        ];
    }
}
