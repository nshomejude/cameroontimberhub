<?php

namespace App\Notifications;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Fired at the buyer when a supplier submits a quote on their RFQ —
 * `QuoteService::submit()` is the trigger. `type`/`reference` mirror the
 * `quote_received` pairing `DashboardController::activity()` already uses,
 * so the mobile client can route this notification with the same
 * `GET /quotes/{reference}` lookup the dashboard activity feed relies on.
 */
class QuoteReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(public Quote $quote) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $supplierName = $this->quote->company?->name ?? 'A supplier';

        return [
            'type' => 'quote_received',
            'title' => 'New quote received',
            'body' => "{$supplierName} submitted a quote for RFQ {$this->quote->rfq?->reference_code}.",
            'reference' => $this->quote->reference_code,
            'screen' => 'quote',
        ];
    }
}
