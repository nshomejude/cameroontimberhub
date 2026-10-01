<?php

namespace App\Notifications;

use App\Enums\CommissionDepositStatus;
use App\Models\CommissionDeposit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a supplier company's users that finance confirmed (amount received,
 * remaining balance) or rejected (with the reason) a commission deposit —
 * App\Services\Commission\CommissionCollectionService. Preference-gated
 * (`commission_deposit`).
 */
class CommissionDepositReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'commission_deposit';

    public function __construct(public CommissionDeposit $deposit) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return CommissionStatementIssuedNotification::channelsFor($notifiable, self::TYPE);
    }

    private function outcome(): string
    {
        return $this->deposit->status === CommissionDepositStatus::Confirmed ? 'confirmed' : 'rejected';
    }

    /** @return array<string, string> */
    private function params(): array
    {
        $statement = $this->deposit->statement;

        return [
            'amount' => $this->deposit->money($this->deposit->amount_received ?? $this->deposit->amount),
            'reference' => (string) $this->deposit->transaction_reference,
            'number' => (string) $statement?->statement_number,
            'balance' => $statement ? $statement->money($statement->outstanding()) : '',
            'reason' => (string) $this->deposit->rejection_reason,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $key = 'notifications.push.commission_deposit_'.$this->outcome();

        return [
            'type' => self::TYPE,
            'title' => __($key.'.title', $this->params()),
            'body' => __($key.'.body', $this->params()),
            'deposit_status' => $this->outcome(),
            'statement_number' => $this->deposit->statement?->statement_number,
            'transaction_reference' => $this->deposit->transaction_reference,
            'screen' => 'commission',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $key = 'notifications.push.commission_deposit_'.$this->outcome();

        $mail = (new MailMessage)
            ->subject(__($key.'.title', $this->params()))
            ->line(__($key.'.body', $this->params()));

        if ($this->deposit->statement) {
            $mail->action(__('notifications.push.commission_action'), CommissionStatementIssuedNotification::statementUrl($this->deposit->statement));
        }

        return $mail;
    }
}
