<?php

namespace App\Notifications;

use App\Models\Dispute;
use App\Models\NotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells BOTH parties to a dispute (the buyer and every member of the
 * supplier company) that staff decided it — resolved (first decision or
 * appeal decision) or closed. Sent by DisputeNotifier from the admin
 * Disputes table. Mail always goes (a decision is a legal-ish outcome that
 * must not be silently muted); `database` follows the per-type preference.
 */
class DisputeResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'dispute_resolved';

    public const EVENT_RESOLVED = 'resolved';

    public const EVENT_APPEAL_DECIDED = 'appeal_decided';

    public const EVENT_CLOSED = 'closed';

    public function __construct(public Dispute $dispute, public string $event = self::EVENT_RESOLVED) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if (NotificationPreference::allows($notifiable, self::TYPE, 'database')) {
            $channels[] = 'database';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->dispute->order?->reference_code;
        $mail = (new MailMessage)
            ->subject(__('notifications.dispute_decision.'.$this->event.'.subject', ['order' => $order]))
            ->line(__('notifications.dispute_decision.'.$this->event.'.line', ['order' => $order]));

        if ($this->event !== self::EVENT_CLOSED && filled($this->dispute->resolution_notes)) {
            $mail->line(__('notifications.dispute_decision.notes', ['notes' => $this->dispute->resolution_notes]));
        }

        return $mail->action(__('notifications.dispute_decision.action'), route('disputes.show', [
            'order' => $this->dispute->order_id,
            'dispute' => $this->dispute->getKey(),
        ]));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $order = $this->dispute->order?->reference_code;

        return [
            'type' => self::TYPE,
            'title' => __('notifications.dispute_decision.'.$this->event.'.title'),
            'body' => __('notifications.dispute_decision.'.$this->event.'.line', ['order' => $order]),
            'reference' => $order,
            'screen' => 'dispute',
            'dispute_id' => $this->dispute->getKey(),
            'event' => $this->event,
        ];
    }
}
