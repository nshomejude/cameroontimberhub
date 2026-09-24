<?php

namespace App\Notifications;

class SupportTicketStaffReplyNotification extends SupportTicketNotification
{
    public const TYPE = 'support_ticket_staff_reply';

    protected function type(): string
    {
        return self::TYPE;
    }

    protected function screen(): string
    {
        return 'support';
    }
}
