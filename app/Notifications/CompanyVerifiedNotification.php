<?php

namespace App\Notifications;

use App\Enums\RfqCompanyStatus;
use App\Enums\RfqStatus;
use App\Models\Company;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells every member of a newly verified company. Mail always goes (it is
 * the one-off "you can now trade" event); the in-app `database` entry and
 * Expo push are gated by NotificationPreference like every sibling, and
 * carry `screen: 'verification'` so the app can deep link to the company's
 * verification status.
 */
class CompanyVerifiedNotification extends Notification
{
    use Queueable;

    public const TYPE = 'company_verified';

    public function __construct(public readonly Company $company) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if (! $notifiable instanceof User) {
            return $channels;
        }

        if (NotificationPreference::allows($notifiable, self::TYPE, 'database')) {
            $channels[] = 'database';
        }

        if (NotificationPreference::allows($notifiable, self::TYPE, 'push')) {
            $channels[] = ExpoPushChannel::class;
        }

        return $channels;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => __('notifications.company_verified.subject', ['company' => $this->company->name]),
            'body' => __('notifications.company_verified.line_1', ['company' => $this->company->name]),
            'company_id' => $this->company->getKey(),
            'screen' => 'verification',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('notifications.company_verified.subject', ['company' => $this->company->name]))
            ->line(__('notifications.company_verified.line_1', ['company' => $this->company->name]))
            ->line(__('notifications.company_verified.line_2'));

        // Requests routed while the company was pending verification could
        // not be answered until now (Company::canRespondToBuyers()).
        if (($waiting = $this->waitingRequests()) > 0) {
            $mail->line(trans_choice('You can now respond to :count buyer request waiting for you.|You can now respond to :count buyer requests waiting for you.', $waiting, ['count' => $waiting]));
        }

        return $mail
            ->action(__('notifications.company_verified.action'), url('/dashboard'))
            ->line(__('notifications.company_verified.line_3'));
    }

    /** Approved RFQs routed to the company that it has not responded to or declined. */
    public function waitingRequests(): int
    {
        return $this->company->rfqRoutings()
            ->whereIn('status', [RfqCompanyStatus::Sent->value, RfqCompanyStatus::Viewed->value])
            ->whereHas('rfq', fn ($q) => $q->where('status', RfqStatus::Approved->value))
            ->count();
    }
}
