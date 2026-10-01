<?php

namespace App\Notifications;

use App\Models\CommissionStatement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Commission statement payment reminders, sent once each by the daily
 * `commission:process-statements` run
 * (App\Services\Commission\CommissionCollectionService::processDueDates()):
 * `due_soon` (timber.commission.reminder_days_before days before the due
 * date) and `overdue` (the day after it passes unpaid). Preference-gated
 * (`commission_statement`) like every sibling.
 */
class CommissionStatementReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'commission_statement';

    public const DUE_SOON = 'due_soon';

    public const OVERDUE = 'overdue';

    public function __construct(public CommissionStatement $statement, public string $kind) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return CommissionStatementIssuedNotification::channelsFor($notifiable, self::TYPE);
    }

    private function key(): string
    {
        return 'notifications.push.commission_statement_'.($this->kind === self::OVERDUE ? 'overdue' : 'due_soon');
    }

    /** @return array<string, string> */
    private function params(): array
    {
        return [
            'number' => $this->statement->statement_number,
            'amount' => $this->statement->money($this->statement->outstanding()),
            'due' => $this->statement->due_date->format('d M Y'),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => __($this->key().'.title', $this->params()),
            'body' => __($this->key().'.body', $this->params()),
            'reminder' => $this->kind,
            'statement_number' => $this->statement->statement_number,
            'outstanding' => $this->statement->outstanding(),
            'currency' => $this->statement->currency->value,
            'due_date' => $this->statement->due_date->toDateString(),
            'screen' => 'commission',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__($this->key().'.title', $this->params()))
            ->line(__($this->key().'.body', $this->params()))
            ->line(__('notifications.push.commission_pay_hint'))
            ->action(__('notifications.push.commission_action'), CommissionStatementIssuedNotification::statementUrl($this->statement));
    }
}
