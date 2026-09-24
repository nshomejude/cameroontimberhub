<?php

namespace App\Notifications;

class SupportTicketOpenedNotification extends SupportTicketNotification
{
    public const TYPE = 'support_ticket_opened';

    protected function type(): string
    {
        return self::TYPE;
    }

    protected function screen(): string
    {
        return 'staff_support';
    }
}
