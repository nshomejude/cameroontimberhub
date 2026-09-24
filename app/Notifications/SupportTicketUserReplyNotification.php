<?php

namespace App\Notifications;

class SupportTicketUserReplyNotification extends SupportTicketNotification
{
    public const TYPE = 'support_ticket_user_reply';

    protected function type(): string
    {
        return self::TYPE;
    }

    protected function screen(): string
    {
        return 'staff_support';
    }
}
