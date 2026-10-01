<?php

namespace App\Services;

use App\Enums\RfqStatus;
use App\Models\Rfq;
use App\Models\User;
use App\Notifications\RfqWithdrawnNotification;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Buyer self-service withdrawal of their own RFQ.
 *
 * RfqStatus has no dedicated "cancelled" case, so a withdrawal lands on the
 * closest terminal status, `closed` — the same terminal state QuoteService
 * uses once a quote is accepted, and the one RfqTriageService::TRANSITIONS
 * treats as final. The admin triage map does not allow `new -> closed`
 * (staff close only after review), so the buyer's lever is its own guard
 * here rather than a widened admin transition table: a buyer may withdraw
 * while the request is still open (new / in_review / approved) and nothing
 * has been awarded on it. Rejected/spam/closed, or any RFQ with an order,
 * refuse.
 *
 * Every company the RFQ was routed to is told (database + mail) so nobody
 * keeps quoting on a dead request. Routing rows keep their status
 * (RfqCompanyStatus has no "withdrawn" case); the RFQ's own `closed` status
 * is what stops further quoting.
 */
class RfqCancellationService
{
    /** @var list<RfqStatus> */
    public const CANCELLABLE = [RfqStatus::New, RfqStatus::InReview, RfqStatus::Approved];

    public function canCancel(Rfq $rfq): bool
    {
        return in_array($rfq->status, self::CANCELLABLE, true) && ! $rfq->orders()->exists();
    }

    public function cancel(Rfq $rfq, User $buyer): Rfq
    {
        if ((int) $rfq->user_id !== (int) $buyer->getKey()) {
            throw new RuntimeException('You can only withdraw your own requests.');
        }

        if (! $this->canCancel($rfq)) {
            throw new RuntimeException(__('messages.account_center.rfq_cancel_not_allowed'));
        }

        $rfq->update(['status' => RfqStatus::Closed]);

        activity('rfq')->performedOn($rfq)->causedBy($buyer)->event('status_changed')
            ->withProperties(['to' => RfqStatus::Closed->value, 'reason' => 'buyer_withdrawn'])
            ->log('RFQ withdrawn by buyer');

        $rfq->load('routings.company.users');
        foreach ($rfq->routings as $routing) {
            if ($routing->company !== null && $routing->company->users->isNotEmpty()) {
                Notification::send($routing->company->users, new RfqWithdrawnNotification($rfq));
            }
        }

        return $rfq->refresh();
    }
}
