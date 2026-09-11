<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Conversation;
use App\Services\MessagingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One inbox row for the buyer mobile app — the API counterpart of an inbox
 * list item in `App\Livewire\Messaging\Inbox`.
 *
 * The counterparty is always the supplier company here: this API is
 * buyer-only (`api.buyer` gate), so `conversation.user_id` is always the
 * token holder and the other side is always `company_id`. Reuses
 * `SupplierResource` rather than inventing a second supplier-card shape.
 *
 * `unread_count` is real, derived state from `MessagingService::unreadCounts()`
 * — never a stored counter. The controller computes the whole page's counts
 * in ONE grouped query and stamps each model with a `unread_count` attribute
 * before wrapping it here (see `ConversationController::withUnreadCounts()`);
 * if that was not done (e.g. a single `show()` conversation), this falls back
 * to a single-conversation lookup so the field is never wrong, just less batched.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $latest = $this->latestMessage;

        $unread = $this->resource->getAttribute('unread_count')
            ?? app(MessagingService::class)->unreadCounts($user, [$this->id])[$this->id]
            ?? 0;

        return [
            'id' => $this->id,
            'subject' => $this->subjectLine(),
            'topic' => $this->topic?->value,
            'status' => $this->status?->value,
            'counterparty' => $this->whenLoaded(
                'company',
                fn () => $this->company ? new SupplierResource($this->company) : null,
            ),
            'last_message' => $latest ? [
                'body' => $latest->preview(160),
                'kind' => $latest->type->value,
                'at' => $latest->created_at?->toIso8601String(),
                'sender' => [
                    'id' => $latest->sender_user_id,
                    'name' => $latest->sender?->name,
                    'is_own' => $latest->isFrom($user),
                ],
            ] : null,
            'unread_count' => $unread,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
