<?php

namespace Database\Factories;

use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Message> */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'type' => MessageType::Text->value,
            'body' => $this->faker->sentence(),
        ];
    }

    public function system(): static
    {
        return $this->state(['type' => MessageType::System->value, 'sender_user_id' => null]);
    }
}
