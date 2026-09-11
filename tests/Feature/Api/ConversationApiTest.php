<?php

use App\Enums\MessageType;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** A real thread between $buyer and a supplier company, with one message from each side. */
function apiConversation(User $buyer): Conversation
{
    $company = Company::factory()->publiclyVisible()->create();
    $supplierUser = User::factory()->create();
    $company->users()->attach($supplierUser);

    $conversation = Conversation::factory()->create([
        'user_id' => $buyer->getKey(),
        'company_id' => $company->getKey(),
        'subject' => 'Azobe decking for marina project',
    ]);

    ConversationParticipant::firstOrCreate(
        ['conversation_id' => $conversation->getKey(), 'user_id' => $supplierUser->getKey()],
        ['role' => ConversationParticipant::ROLE_SUPPLIER, 'company_id' => $company->getKey()],
    );

    Message::factory()->create([
        'conversation_id' => $conversation->getKey(),
        'type' => MessageType::System->value,
        'sender_user_id' => null,
        'body' => 'Conversation started with '.$company->name.'.',
    ]);

    $buyerMessage = Message::factory()->create([
        'conversation_id' => $conversation->getKey(),
        'sender_user_id' => $buyer->getKey(),
        'body' => 'Can you confirm the delivery port?',
    ]);

    $supplierMessage = Message::factory()->create([
        'conversation_id' => $conversation->getKey(),
        'sender_user_id' => $supplierUser->getKey(),
        'sender_company_id' => $company->getKey(),
        'body' => 'We ship out of Douala.',
    ]);

    $conversation->forceFill(['last_message_at' => $supplierMessage->created_at])->save();

    return $conversation->fresh();
}

it('lists the buyer inbox with real unread counts, paginated', function () {
    $buyer = User::factory()->create();
    apiConversation($buyer);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/conversations')
        ->assertOk();

    $response->assertJsonStructure([
        'data' => [['id', 'subject', 'topic', 'status', 'counterparty', 'last_message', 'unread_count', 'updated_at']],
        'links',
        'meta',
    ]);

    expect($response->json('data.0.unread_count'))->toBe(2)
        ->and($response->json('data.0.last_message.kind'))->toBe('text')
        ->and($response->json('data.0.last_message.sender.is_own'))->toBeFalse();
});

it('shows one conversation the buyer participates in', function () {
    $buyer = User::factory()->create();
    $conversation = apiConversation($buyer);

    $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $conversation->id)
        ->assertJsonPath('data.subject', 'Azobe decking for marina project');
});

it('404s a conversation the buyer does not participate in, not 403', function () {
    $buyer = User::factory()->create();
    $stranger = User::factory()->create();
    $conversation = apiConversation($stranger);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}")
        ->assertNotFound();

    expect($response->json('error.code'))->toBe('not_found');
});

it('returns thread messages oldest-first with correct is_own per side and a real kind', function () {
    $buyer = User::factory()->create();
    $conversation = apiConversation($buyer);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}/messages")
        ->assertOk();

    $bodies = $response->json('data.*.body');
    expect($bodies)->toContain('Can you confirm the delivery port?', 'We ship out of Douala.');

    $kinds = collect($response->json('data'))->pluck('kind')->all();
    expect($kinds)->toContain('system')->toContain('text');

    $ownMessage = collect($response->json('data'))->firstWhere('body', 'Can you confirm the delivery port?');
    $otherMessage = collect($response->json('data'))->firstWhere('body', 'We ship out of Douala.');

    expect($ownMessage['sender']['is_own'])->toBeTrue()
        ->and($otherMessage['sender']['is_own'])->toBeFalse();

    // oldest-first ordering: system note, then buyer message, then supplier message
    expect($response->json('data.0.kind'))->toBe('system');
});

it('posts a plain message which then appears in the thread', function () {
    $buyer = User::factory()->create();
    $conversation = apiConversation($buyer);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'One more question about incoterms.'])
        ->assertCreated();

    expect($response->json('data.body'))->toBe('One more question about incoterms.')
        ->and($response->json('data.kind'))->toBe('text')
        ->and($response->json('data.sender.is_own'))->toBeTrue();

    $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}/messages")
        ->assertOk()
        ->assertSee('One more question about incoterms.');
});

it('rejects an empty or too-long message body with a 422 validation envelope', function () {
    $buyer = User::factory()->create();
    $conversation = apiConversation($buyer);

    $empty = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => ''])
        ->assertStatus(422);

    expect($empty->json('error.code'))->toBe('validation_failed')
        ->and($empty->json('error.details'))->toHaveKey('body');

    $tooLong = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => str_repeat('a', 4001)])
        ->assertStatus(422);

    expect($tooLong->json('error.details'))->toHaveKey('body');
});

it('404s posting to a conversation the buyer does not belong to', function () {
    $buyer = User::factory()->create();
    $stranger = User::factory()->create();
    $conversation = apiConversation($stranger);

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'Hello?'])
        ->assertNotFound();
});

it('marks a conversation read, zeroing the unread count', function () {
    $buyer = User::factory()->create();
    $conversation = apiConversation($buyer);

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/conversations')
        ->assertJsonPath('data.0.unread_count', 2);

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/read")
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/conversations')
        ->assertJsonPath('data.0.unread_count', 0);
});

it('requires auth for every conversations endpoint', function () {
    $buyer = User::factory()->create();
    $conversation = apiConversation($buyer);

    $this->getJson('/api/v1/conversations')->assertUnauthorized();
    $this->getJson("/api/v1/conversations/{$conversation->id}")->assertUnauthorized();
    $this->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertUnauthorized();
    $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'hi'])->assertUnauthorized();
    $this->postJson("/api/v1/conversations/{$conversation->id}/read")->assertUnauthorized();
});

it('rejects a non-buyer (supplier) account the same way other buyer endpoints do', function () {
    $supplierUser = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($supplierUser);

    $ordersResponse = $this->actingAs($supplierUser, 'sanctum')->getJson('/api/v1/orders');
    $conversationsResponse = $this->actingAs($supplierUser, 'sanctum')->getJson('/api/v1/conversations');

    $conversationsResponse->assertStatus($ordersResponse->getStatusCode());
});
