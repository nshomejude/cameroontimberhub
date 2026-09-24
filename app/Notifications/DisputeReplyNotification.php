<?php

namespace App\Notifications;

use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Fired when a reply posts to a dispute — wired from
 * `DisputeController::reply()` (NOT `DisputeService::reply()` itself, since
 * that file had an in-flight change from another agent when this was built;
 * see the controller for the note). Sent to the other party only.
 */
class DisputeReplyNotification extends Notification
{
    use Queueable;

    public function __construct(public Dispute $dispute, public string $body) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'dispute_reply',
            'title' => 'New reply on your dispute',
            'body' => Str::limit($this->body, 140),
            'reference' => $this->dispute->order?->reference_code,
            'screen' => 'dispute',
        ];
    }
}
