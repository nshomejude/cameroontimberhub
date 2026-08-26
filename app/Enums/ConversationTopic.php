<?php

namespace App\Enums;

/**
 * The "Message about" chips on the New Conversation screen.
 *
 * Mirrors the `conversations_topic_check` CHECK constraint exactly.
 */
enum ConversationTopic: string
{
    case General = 'general';
    case Rfq = 'rfq';
    case Order = 'order';
    case Product = 'product';
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General Inquiry',
            self::Rfq => 'Request for Quote',
            self::Order => 'Existing Order',
            self::Product => 'Product Enquiry',
            self::Support => 'After Sales Support',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::General => 'chat-bubble-left-right',
            self::Rfq => 'document-text',
            self::Order => 'cube',
            self::Product => 'squares-2x2',
            self::Support => 'lifebuoy',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
