<?php

namespace App\Services;

use App\Enums\RfqStatus;
use App\Models\CompanyInquiry;
use App\Models\User;
use RuntimeException;

/**
 * Admin inquiry triage state machine, mirroring RfqTriageService. Reuses
 * RfqStatus since CompanyInquiry::status already casts to it. No route()
 * method: an inquiry is already scoped to the one company the buyer
 * contacted, unlike an RFQ which fans out to multiple exporters.
 */
class InquiryTriageService
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

    public function transition(CompanyInquiry $inquiry, RfqStatus $to, User $actor, ?string $reason = null): void
    {
        if (! in_array($to->value, self::TRANSITIONS[$inquiry->status->value] ?? [], true)) {
            throw new RuntimeException("Illegal inquiry transition {$inquiry->status->value} -> {$to->value}");
        }

        $inquiry->update(['status' => $to]);

        activity('inquiry')->performedOn($inquiry)->causedBy($actor)->event('status_changed')
            ->withProperties(['to' => $to->value, 'reason' => $reason])->log("Inquiry status -> {$to->value}");
    }

    public function startReview(CompanyInquiry $inquiry, User $actor): void
    {
        $this->transition($inquiry, RfqStatus::InReview, $actor);
    }

    public function approve(CompanyInquiry $inquiry, User $actor): void
    {
        $this->transition($inquiry, RfqStatus::Approved, $actor);
    }

    public function reject(CompanyInquiry $inquiry, string $reason, User $actor): void
    {
        $this->transition($inquiry, RfqStatus::Rejected, $actor, $reason);
    }

    public function markSpam(CompanyInquiry $inquiry, User $actor): void
    {
        $this->transition($inquiry, RfqStatus::Spam, $actor);
    }

    public function close(CompanyInquiry $inquiry, User $actor): void
    {
        $this->transition($inquiry, RfqStatus::Closed, $actor);
    }
}
