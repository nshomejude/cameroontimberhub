<?php

namespace App\Notifications;

use App\Models\CommissionStatement;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a supplier company's users that their monthly marketplace-commission
 * statement was issued (App\Services\Commission\CommissionStatementIssuer),
 * with the amount, due date and where to pay. Mail, in-app and push are all
 * preference-gated (`commission_statement`).
 */
class CommissionStatementIssuedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'commission_statement';

    public function __construct(public CommissionStatement $statement) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return self::channelsFor($notifiable, self::TYPE);
    }

    /**
     * Shared by the commission-collection notifications: every channel
     * respects the user's notification preferences.
     *
     * @return array<int, string>
     */
    public static function channelsFor(object $notifiable, string $type): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        $channels = [];

        if (NotificationPreference::allows($notifiable, $type, 'mail')) {
            $channels[] = 'mail';
        }

        if (NotificationPreference::allows($notifiable, $type, 'database')) {
            $channels[] = 'database';
        }

        if (NotificationPreference::allows($notifiable, $type, 'push')) {
            $channels[] = ExpoPushChannel::class;
        }

        return $channels;
    }

    /** @return array<string, string> */
    private function params(): array
    {
        return [
            'number' => $this->statement->statement_number,
            'period' => $this->statement->periodLabel(),
            'amount' => $this->statement->money(),
            'due' => $this->statement->due_date->format('d M Y'),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.commission_statement_issued.title', $this->params()),
            'body' => __('notifications.push.commission_statement_issued.body', $this->params()),
            'statement_number' => $this->statement->statement_number,
            'amount' => (string) $this->statement->total_amount,
            'currency' => $this->statement->currency->value,
            'due_date' => $this->statement->due_date->toDateString(),
            'screen' => 'commission',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.push.commission_statement_issued.title', $this->params()))
            ->line(__('notifications.push.commission_statement_issued.body', $this->params()))
            ->line(__('notifications.push.commission_pay_hint'))
            ->action(__('notifications.push.commission_action'), self::statementUrl($this->statement));
    }

    /** Exporter-panel URL of the statement (falls back to the panel root). */
    public static function statementUrl(CommissionStatement $statement): string
    {
        try {
            return \App\Filament\Exporter\Resources\CommissionStatements\CommissionStatementResource::getUrl(
                'view', ['record' => $statement], panel: 'exporter',
            );
        } catch (\Throwable) {
            return url('/dashboard');
        }
    }
}
