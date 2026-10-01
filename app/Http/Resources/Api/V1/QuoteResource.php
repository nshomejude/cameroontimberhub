<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A supplier's quotation, as the buyer who raised the RFQ may see it.
 *
 * Buyer-visible statuses only ever reach here (Quote::scopeBuyerVisible), so a
 * draft or withdrawn quote is filtered out upstream rather than redacted here.
 * Supplier-side internals (`rfq_company_id`, `revision`, `supersedes_quote_id`,
 * negotiation rounds) are not part of the v1 buyer payload.
 *
 * @mixin Quote
 */
class QuoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference_code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'currency' => $this->currency?->value,
            'incoterm' => $this->incoterm?->value,
            'subtotal_amount' => $this->subtotal_amount,
            'shipping_amount' => $this->shipping_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'lead_time_days' => $this->lead_time_days,
            'validity_days' => $this->validity_days,
            'valid_until' => $this->valid_until?->toDateString(),
            'payment_terms' => $this->payment_terms,
            'notes' => $this->notes,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decline_reason' => $this->decline_reason,
            'is_expired' => $this->isExpired(),
            'is_actionable' => $this->isActionable(),
            'rfq_reference' => $this->whenLoaded('rfq', fn () => $this->rfq->reference_code),
            'supplier' => $this->whenLoaded('company', fn () => new SupplierResource($this->company)),
            'items' => QuoteItemResource::collection($this->whenLoaded('items')),
            'conversation_id' => self::conversationIdFor($this->resource),
        ];
    }

    /**
     * The chat thread this quote lives in, if any — so the client can call
     * `conversations/{id}/quotes/{quote}/counter|accept|decline`. A quote
     * reaches a thread either as the thread's own `conversations.quote_id`
     * or (the common case: the exporter "Share in chat" action / an
     * in-thread RFQ) as a quotation card, i.e. a message whose `related_*`
     * points at it (`MessagingService::postQuotation()`). Latest wins. Null
     * when the quote was never shared into a conversation.
     */
    public static function conversationIdFor(Quote $quote): ?int
    {
        // Preloaded by Quote::scopeWithConversationId() on list endpoints.
        if (array_key_exists('resolved_conversation_id', $quote->getAttributes())) {
            $preloaded = $quote->getAttributes()['resolved_conversation_id'];

            return $preloaded === null ? null : (int) $preloaded;
        }

        $viaCard = Message::query()
            ->where('related_type', $quote->getMorphClass())
            ->where('related_id', $quote->getKey())
            ->max('conversation_id');

        if ($viaCard !== null) {
            return (int) $viaCard;
        }

        $direct = Conversation::query()->where('quote_id', $quote->getKey())->max('id');

        return $direct === null ? null : (int) $direct;
    }
}
