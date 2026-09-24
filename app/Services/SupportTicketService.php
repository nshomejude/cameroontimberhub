<?php

namespace App\Services;

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketStatus;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketOpenedNotification;
use App\Notifications\SupportTicketStaffReplyNotification;
use App\Notifications\SupportTicketUserReplyNotification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Single authority for support-ticket state changes, shared by the API
 * controllers and the Filament staff resource.
 *
 * Transitions:
 *  - user opens            -> open
 *  - user replies          -> open (from open/pending/resolved); closed = refused
 *  - staff replies         -> pending by default, or the explicit status given
 */
class SupportTicketService
{
    public function __construct(
        private readonly BuyerApiScope $buyerScope,
        private readonly SupplierApiScope $supplierScope,
    ) {}

    /** An order the user can see (as buyer or as supplier), or null. */
    public function visibleOrder(User $user, string $reference): ?Order
    {
        try {
            return $this->buyerScope->order($user, $reference);
        } catch (ModelNotFoundException) {
        }

        try {
            return $this->supplierScope->order($user, $reference);
        } catch (ModelNotFoundException) {
        }

        return null;
    }

    public function open(User $user, string $subject, SupportTicketCategory $category, string $body, ?Order $order = null): SupportTicket
    {
        $ticket = DB::transaction(function () use ($user, $subject, $category, $body, $order) {
            $ticket = SupportTicket::create([
                'reference' => SupportTicket::generateReference(),
                'user_id' => $user->getKey(),
                'order_id' => $order?->getKey(),
                'subject' => $subject,
                'category' => $category,
                'status' => SupportTicketStatus::Open,
                'last_activity_at' => now(),
            ]);

            $ticket->messages()->create([
                'user_id' => $user->getKey(),
                'is_staff' => false,
                'body' => $body,
            ]);

            return $ticket;
        });

        $this->notifyStaff(new SupportTicketOpenedNotification($ticket, $subject), $user);

        return $ticket;
    }

    public function userReply(SupportTicket $ticket, User $user, string $body): void
    {
        if ($ticket->status === SupportTicketStatus::Closed) {
            throw new RuntimeException('This ticket is closed. Please open a new ticket.');
        }

        DB::transaction(function () use ($ticket, $user, $body) {
            $ticket->messages()->create([
                'user_id' => $user->getKey(),
                'is_staff' => false,
                'body' => $body,
            ]);

            $ticket->forceFill([
                'status' => SupportTicketStatus::Open,
                'last_activity_at' => now(),
            ])->save();
        });

        $this->notifyStaff(new SupportTicketUserReplyNotification($ticket, $body), $user);
    }

    public function staffReply(SupportTicket $ticket, User $staff, string $body, ?SupportTicketStatus $status = null): void
    {
        $status ??= SupportTicketStatus::Pending;

        DB::transaction(function () use ($ticket, $staff, $body, $status) {
            $ticket->messages()->create([
                'user_id' => $staff->getKey(),
                'is_staff' => true,
                'body' => $body,
            ]);

            $ticket->forceFill([
                'status' => $status,
                'last_activity_at' => now(),
                'closed_at' => $status === SupportTicketStatus::Closed ? now() : null,
            ])->save();
        });

        $ticket->user?->notify(new SupportTicketStaffReplyNotification($ticket, $body));
    }

    /** Status change without a message (Filament). */
    public function changeStatus(SupportTicket $ticket, SupportTicketStatus $status): void
    {
        $ticket->forceFill([
            'status' => $status,
            'closed_at' => $status === SupportTicketStatus::Closed ? now() : null,
        ])->save();
    }

    private function notifyStaff(object $notification, User $actor): void
    {
        $recipients = SupportTicket::staffRecipients()
            ->reject(fn (User $u) => (int) $u->getKey() === (int) $actor->getKey());

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notification);
        }
    }
}
