<?php

namespace App\Enums;

/**
 * The kind of a message.
 *
 * Deliberately a single `messages` table with a `type` discriminator and a
 * `payload` JSONB column rather than a table per card:
 *
 *  - a thread is ONE ordered stream, and one table means one indexed
 *    `(conversation_id, created_at)` read instead of a UNION across N tables;
 *  - the later phases (quotation card, proforma invoice, payment request,
 *    shipment update, review request, reorder) each add a case here plus a
 *    Blade partial — no migration, no new relation on Conversation;
 *  - `payload` snapshots the figures that must stay immutable (an agreed price
 *    never changes retroactively), while `related_type`/`related_id` point at
 *    the live record so status-like fields are read fresh.
 *
 * Mirrors the `messages_type_check` CHECK constraint exactly.
 */
enum MessageType: string
{
    /** A person typing prose. Escaped by Blade; never rendered as HTML. */
    case Text = 'text';

    /** Platform-authored, no sender. "Conversation started about …". */
    case System = 'system';

    /** Order reference card — snapshot money, live status. */
    case OrderReference = 'order_reference';

    /** Order status trail card — milestones read live from the order. */
    case OrderStatus = 'order_status';

    /** The product context header card at the top of the thread. */
    case ProductReference = 'product_reference';

    /** RFQ posted from the in-thread composer — snapshot lines, live status. */
    case RfqReference = 'rfq_reference';

    /** Supplier quotation card — snapshot money, live quote status. */
    case Quotation = 'quotation';

    /** One negotiation round: a proposed price/quantity/terms from one side. */
    case CounterOffer = 'counter_offer';

    /** The recorded acceptance of a quotation's terms. Never a signature. */
    case ContractAcceptance = 'contract_acceptance';

    /** Blade partial under resources/views/public/messages/types/. */
    public function partial(): string
    {
        return 'public.messages.types.'.str_replace('_', '-', $this->value);
    }

    /** True when this type renders a structured card rather than a bubble. */
    public function isCard(): bool
    {
        return in_array($this, [
            self::OrderReference,
            self::OrderStatus,
            self::ProductReference,
            self::RfqReference,
            self::Quotation,
            self::CounterOffer,
            self::ContractAcceptance,
        ], true);
    }

    /** Only prose typed by a person may be deleted by its author. */
    public function isDeletable(): bool
    {
        return $this === self::Text;
    }

    /** One-line inbox preview for a card that has no body of its own. */
    public function previewFallback(): string
    {
        return match ($this) {
            self::Text => '',
            self::System => 'Conversation update',
            self::OrderReference => 'Shared an order reference',
            self::OrderStatus => 'Shared the order status',
            self::ProductReference => 'Shared a product',
            self::RfqReference => 'Sent a request for quote',
            self::Quotation => 'Sent a quotation',
            self::CounterOffer => 'Sent a counter-offer',
            self::ContractAcceptance => 'Accepted the quotation terms',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
