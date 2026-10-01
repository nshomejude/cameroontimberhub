<?php

namespace App\Services;

use App\Enums\ConsentPurpose;
use App\Enums\RfqStatus;
use App\Mail\BuyerRfqRejectedMail;
use App\Mail\BuyerRfqRoutedMail;
use App\Models\Company;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Notifications\RfqRoutedToExporter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Admin RFQ triage state machine (spec §6) + routing to verified exporters.
 */
class RfqTriageService
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'new' => ['in_review', 'approved', 'rejected', 'spam', 'closed'],
        'in_review' => ['approved', 'rejected', 'spam', 'closed'],
        'approved' => ['closed', 'rejected'],
        'rejected' => ['closed'],
        'spam' => ['rejected', 'closed'],
        'closed' => [],
    ];

    public function transition(Rfq $rfq, RfqStatus $to, ?User $actor, ?string $reason = null): void
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

    /**
     * Approve the RFQ and — unless `timber.rfq.auto_route_on_approval` is
     * off — immediately route it as an "open request" to every matching,
     * entitled supplier (RfqOpenRequestService::autoRoute()). A null actor
     * is the system (auto-approval of a clean, verified RFQ). Staff can
     * still route additional companies manually afterwards.
     */
    /** @return int number of companies the RFQ was auto-routed to */
    public function approve(Rfq $rfq, ?User $actor): int
    {
        $this->transition($rfq, RfqStatus::Approved, $actor);

        return app(RfqOpenRequestService::class)->autoRoute($rfq->refresh(), $actor);
    }

    /**
     * Reject and tell the buyer, politely and with the reason. Not sent for
     * spam-flagged or never-verified RFQs (no confirmed human to tell).
     */
    public function reject(Rfq $rfq, string $reason, User $actor): void
    {
        $this->transition($rfq, RfqStatus::Rejected, $actor, $reason);

        if (! $rfq->is_spam && $rfq->email_verified_at !== null && filled($rfq->buyer_email)) {
            $this->mailBuyer(fn () => Mail::to($rfq->buyer_email)->queue(new BuyerRfqRejectedMail($rfq, $reason)), $rfq);
        }
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
     * Refuses to route an RFQ whose consent has been revoked — this is the
     * enforcement half of the persisted Consent ledger (see
     * docs/superpowers/plans/2026-08-27-persisted-consent.md): a revoked
     * "share with verified exporters" consent must stop this feed, not just
     * flip a column nothing reads. An RFQ with no Consent row at all (e.g.
     * one created before this plan shipped, or via a path that does not yet
     * collect consent) is treated as routable, matching today's behaviour —
     * only an explicit revocation blocks routing.
     *
     * @param  list<int>  $companyIds
     */
    public function route(Rfq $rfq, array $companyIds, User $actor, LeadFlowService $leads): int
    {
        return $this->routeDetailed($rfq, $companyIds, $actor, $leads)->routed;
    }

    /**
     * route(), but reporting every requested company that was NOT routed and
     * why, so the admin UI can warn rather than toast "Routed to 0". When at
     * least one new routing is created the buyer is emailed once.
     *
     * @param  list<int>  $companyIds
     */
    public function routeDetailed(Rfq $rfq, array $companyIds, ?User $actor, LeadFlowService $leads): RfqRoutingResult
    {
        if ($rfq->status !== RfqStatus::Approved) {
            throw new RuntimeException('Only approved RFQs can be routed.');
        }

        if ($rfq->consents()->where('purpose', ConsentPurpose::RfqExporterSharing->value)->exists()
            && ! $rfq->hasActiveConsent(ConsentPurpose::RfqExporterSharing)) {
            return new RfqRoutingResult(0, array_map(fn ($id) => [
                'company_id' => (int) $id,
                'company' => Company::find($id)?->legal_name,
                'reason' => RfqRoutingResult::REASON_CONSENT_REVOKED,
            ], array_values($companyIds)));
        }

        $routed = 0;
        $skipped = [];
        foreach ($companyIds as $companyId) {
            $company = Company::find($companyId);

            // leads_receive entitlement (docs/PRICING_SPEC.md §5) -- a
            // company whose plan does not include lead delivery is skipped
            // here (not an error), but reported back in the result so the
            // admin sees it instead of a silent "Routed to 0".
            if (! $company) {
                $skipped[] = ['company_id' => (int) $companyId, 'company' => null, 'reason' => RfqRoutingResult::REASON_NOT_FOUND];

                continue;
            }

            // Draft/suspended/rejected/archived companies never receive
            // buyer requests (Company::BUYER_REQUEST_STATUSES).
            if (! $company->canReceiveBuyerRequests()) {
                $skipped[] = ['company_id' => (int) $companyId, 'company' => $company->legal_name, 'reason' => RfqRoutingResult::REASON_INELIGIBLE_STATUS];

                continue;
            }

            if (! $company->hasFeature('leads_receive')) {
                $skipped[] = ['company_id' => (int) $companyId, 'company' => $company->legal_name, 'reason' => RfqRoutingResult::REASON_NO_ENTITLEMENT];

                continue;
            }

            $routing = RfqCompany::firstOrCreate(
                ['rfq_id' => $rfq->getKey(), 'company_id' => $companyId],
                ['status' => 'sent', 'routed_by' => $actor?->getKey(), 'routed_at' => now()],
            );

            if ($routing->wasRecentlyCreated) {
                $leads->createFromRouting($routing);
                Notification::send($routing->company->users, new RfqRoutedToExporter($rfq, $routing->company));
                $routed++;
            } else {
                $skipped[] = ['company_id' => (int) $companyId, 'company' => $company->legal_name, 'reason' => RfqRoutingResult::REASON_ALREADY_ROUTED];
            }
        }

        if ($routed > 0 && filled($rfq->buyer_email)) {
            $this->mailBuyer(fn () => Mail::to($rfq->buyer_email)->queue(
                new BuyerRfqRoutedMail($rfq, $routed, app(BuyerRfqAccess::class)->responsesUrl($rfq)),
            ), $rfq);
        }

        return new RfqRoutingResult($routed, $skipped);
    }

    /** Buyer emails are best-effort: the triage action itself has succeeded. */
    private function mailBuyer(callable $send, Rfq $rfq): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::channel('errors')->error('Buyer RFQ status mail failed', [
                'rfq_id' => $rfq->getKey(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
