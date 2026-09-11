<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Message;
use App\Services\MessagingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in a thread, for the buyer mobile app.
 *
 * `kind` is `Message::type`'s real value (see `App\Enums\MessageType`) — no
 * invented discriminator. Structured/"card" messages (order_reference,
 * product_reference, rfq_reference, quotation, counter_offer,
 * contract_acceptance, and the Phase 3/4 lifecycle kinds) are rendered
 * READ-ONLY here via their existing `payload` snapshot: this API has no
 * endpoint that lets a client create any of them — see ConversationController's
 * docblock and docs/api/MOBILE_APP_INTEGRATION.md.
 *
 * `is_read` mirrors `MessagingService::isReadByCounterparty()`: for a message
 * this user sent, has the *other* side read it yet. For a message the other
 * side sent, "read" is meaningless from this user's perspective (they either
 * see it or they don't), so it is always `true` there — nothing for the
 * client to render as "unread" on someone else's own words.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isOwn = $this->isFrom($user);

        return [
            'id' => $this->id,
            'kind' => $this->type->value,
            'body' => $this->body,
            'payload' => $this->payload,
            'sender' => [
                'id' => $this->sender_user_id,
                'name' => $this->sender?->name,
                'company_name' => $this->senderCompany?->name,
                'is_own' => $isOwn,
            ],
            'is_read' => $isOwn ? app(MessagingService::class)->isReadByCounterparty($this->resource, $user) : true,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
