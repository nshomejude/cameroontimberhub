<?php

namespace App\Notifications;

use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts staff holding `disputes.manage` that a party appealed a resolved
 * dispute, so it needs to be moved back to review and re-decided. Sent by
 * DisputeNotifier::appealed() from the web and API appeal endpoints. Staff alerts are operational, so they
 * are not preference-gated.
 */
class DisputeAppealedStaffNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'dispute_appealed_staff';

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
            ->subject(__('notifications.dispute_appealed_staff.subject', ['order' => $order]))
            ->line(__('notifications.dispute_appealed_staff.line', [
                'order' => $order,
                'category' => $this->dispute->category?->value,
            ]))
            ->action(__('notifications.dispute_appealed_staff.action'), url('/admin/disputes'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $order = $this->dispute->order?->reference_code;

        return [
            'type' => self::TYPE,
            'title' => __('notifications.dispute_appealed_staff.title'),
            'body' => __('notifications.dispute_appealed_staff.subject', ['order' => $order]),
            'reference' => $order,
            'screen' => 'dispute',
            'dispute_id' => $this->dispute->getKey(),
        ];
    }
}
