<?php

namespace App\Services;

use App\Enums\RfqStatus;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Notifications\RfqRoutedToExporter;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Admin RFQ triage state machine (spec §6) + routing to verified exporters.
 */
class RfqTriageService
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'new' => ['in_review', 'approved', 'rejected', 'spam'],
        'in_review' => ['approved', 'rejected', 'spam', 'closed'],
        'approved' => ['closed', 'rejected'],
        'rejected' => ['closed'],
        'spam' => ['rejected', 'closed'],
        'closed' => [],
    ];

    public function transition(Rfq $rfq, RfqStatus $to, User $actor, ?string $reason = null): void
    {
        if (! in_array($to->value, self::TRANSITIONS[$rfq->status->value] ?? [], true)) {
            throw new RuntimeException("Illegal RFQ transition {$rfq->status->value} -> {$to->value}");
        }

        $data = ['status' => $to];
        if ($to === RfqStatus::Spam) {
            $data['is_spam'] = true;
        }
        $rfq->update($data);

        activity('rfq')->performedOn($rfq)->causedBy($actor)->event('status_changed')
            ->withProperties(['to' => $to->value, 'reason' => $reason])->log("RFQ status -> {$to->value}");
    }

    public function startReview(Rfq $rfq, User $actor): void
    {
        $this->transition($rfq, RfqStatus::InReview, $actor);
    }

    public function approve(Rfq $rfq, User $actor): void
    {
        $this->transition($rfq, RfqStatus::Approved, $actor);
    }

    public function reject(Rfq $rfq, string $reason, User $actor): void
    {
        $this->transition($rfq, RfqStatus::Rejected, $actor, $reason);
    }

    public function markSpam(Rfq $rfq, User $actor): void
    {
        $this->transition($rfq, RfqStatus::Spam, $actor);
    }

    public function close(Rfq $rfq, User $actor): void
    {
        $this->transition($rfq, RfqStatus::Closed, $actor);
    }

    /**
     * Route an approved RFQ to verified exporters. Idempotent (unique rfq_id,
     * company_id); each new routing creates a lead and notifies the exporter.
     *
     * @param  list<int>  $companyIds
     */
    public function route(Rfq $rfq, array $companyIds, User $actor, LeadFlowService $leads): int
    {
        if ($rfq->status !== RfqStatus::Approved) {
            throw new RuntimeException('Only approved RFQs can be routed.');
        }

        $routed = 0;
        foreach ($companyIds as $companyId) {
            $routing = RfqCompany::firstOrCreate(
                ['rfq_id' => $rfq->getKey(), 'company_id' => $companyId],
                ['status' => 'sent', 'routed_by' => $actor->getKey(), 'routed_at' => now()],
            );

            if ($routing->wasRecentlyCreated) {
                $leads->createFromRouting($routing);
                Notification::send($routing->company->users, new RfqRoutedToExporter($rfq));
                $routed++;
            }
        }

        return $routed;
    }
}
