<?php

namespace App\Notifications;

use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts staff holding `disputes.manage` that a new dispute needs attention.
 * Sent by DisputeNotifier::opened(). Staff alerts are operational, so they
 * are not preference-gated.
 */
class DisputeOpenedStaffNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'dispute_opened_staff';

    public function __construct(public Dispute $dispute) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->dispute->order?->reference_code;

        return (new MailMessage)
            ->subject(__('notifications.dispute_opened_staff.subject', ['order' => $order]))
            ->line(__('notifications.dispute_opened_staff.line', [
                'order' => $order,
                'category' => $this->dispute->category?->value,
            ]))
            ->action(__('notifications.dispute_opened_staff.action'), url('/admin/disputes'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $order = $this->dispute->order?->reference_code;

        return [
            'type' => self::TYPE,
            'title' => __('notifications.dispute_opened_staff.title'),
            'body' => __('notifications.dispute_opened_staff.subject', ['order' => $order]),
            'reference' => $order,
            'screen' => 'dispute',
            'dispute_id' => $this->dispute->getKey(),
        ];
    }
}
