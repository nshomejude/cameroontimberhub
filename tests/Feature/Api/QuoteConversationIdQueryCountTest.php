<?php

use App\Http\Resources\Api\V1\QuoteResource;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
 * conversation_id on quote list endpoints is preloaded via
 * Quote::scopeWithConversationId() — same values as the per-quote
 * QuoteResource::conversationIdFor() lookup, but no queries per row.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** Links quote 0 via a quotation-card message and quote 1 via conversations.quote_id. */
function linkQuotesToConversations(Collection $quotes): array
{
    $card = Conversation::factory()->create();
    Message::factory()->create([
        'conversation_id' => $card->getKey(),
        'related_type' => $quotes[0]->getMorphClass(),
        'related_id' => $quotes[0]->getKey(),
    ]);
    $direct = Conversation::factory()->create(['quote_id' => $quotes[1]->getKey()]);

    return [$card->getKey(), $direct->getKey()];
}

function countQueries(callable $fn): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = $fn();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return [$count, $result];
}

function assertConversationIds(array $rows, Collection $quotes, int $cardId, int $directId): void
{
    $byRef = collect($rows)->keyBy('reference');

    foreach ($quotes as $quote) {
        expect($byRef[$quote->reference_code]['conversation_id'])
            ->toBe(QuoteResource::conversationIdFor(Quote::findOrFail($quote->getKey())));
    }

    expect($byRef[$quotes[0]->reference_code]['conversation_id'])->toBe($cardId)
        ->and($byRef[$quotes[1]->reference_code]['conversation_id'])->toBe($directId);
}

it('buyer rfq quotes listing: identical conversation_id, constant query count', function () {
    $run = function (int $n) {
        $buyer = User::factory()->create();
        $rfq = Rfq::factory()->approved()->create(['user_id' => $buyer->id, 'buyer_email' => $buyer->email]);
        $quotes = Quote::factory()->submitted()->count($n)->create(['rfq_id' => $rfq->getKey()]);
        [$card, $direct] = linkQuotesToConversations($quotes);

        [$count, $rows] = countQueries(fn () => $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/rfqs/'.$rfq->reference_code.'/quotes')->assertOk()->json('data'));

        assertConversationIds($rows, $quotes, $card, $direct);

        return $count;
    };

    expect($run(6))->toBe($run(2));
});

it('supplier quotes listing: identical conversation_id, constant query count', function () {
    $run = function (int $n) {
        $user = User::factory()->create();
        $company = Company::factory()->publiclyVisible()->create();
        $company->users()->attach($user);
        $quotes = Quote::factory()->submitted()->count($n)->create(['company_id' => $company->getKey()]);
        [$card, $direct] = linkQuotesToConversations($quotes);

        [$count, $rows] = countQueries(fn () => $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/supplier/quotes')->assertOk()->json('data'));

        assertConversationIds($rows, $quotes, $card, $direct);

        return $count;
    };

    expect($run(6))->toBe($run(2));
});
