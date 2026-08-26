<?php

namespace Database\Factories;

use App\Enums\ConversationStatus;
use App\Enums\ConversationTopic;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conversation> */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'topic' => ConversationTopic::General->value,
            'status' => ConversationStatus::Open->value,
            'last_message_at' => now(),
        ];
    }

    /** Create the buyer participant row the same way MessagingService does. */
    public function configure(): static
    {
        return $this->afterCreating(function (Conversation $conversation) {
            ConversationParticipant::firstOrCreate(
                ['conversation_id' => $conversation->getKey(), 'user_id' => $conversation->user_id],
                ['role' => ConversationParticipant::ROLE_BUYER],
            );
        });
    }
}
