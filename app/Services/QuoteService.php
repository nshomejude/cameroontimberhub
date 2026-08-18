<?php

namespace App\Services;

use App\Enums\QuoteStatus;
use App\Enums\RfqCompanyStatus;
use App\Enums\RfqStatus;
use App\Mail\QuoteSubmittedMail;
use App\Models\Company;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Quote lifecycle state machine, mirroring RfqTriageService's shape.
 *
 * Supplier: submit / withdraw. Buyer: markViewed / accept / decline.
 * System: expire. Every move goes through transition(), which is the only
 * place `status` is written, and every move is logged to the activity log.
 */
class QuoteService
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'draft' => ['submitted', 'withdrawn'],
        'submitted' => ['viewed', 'accepted', 'declined', 'withdrawn', 'expired'],
        'viewed' => ['accepted', 'declined', 'withdrawn', 'expired'],
        'accepted' => [],
        'declined' => [],
        'withdrawn' => [],
        'expired' => [],
    ];

    public function __construct(
        private readonly QuoteReferenceGenerator $references,
        private readonly LeadFlowService $leads,
    ) {}

    /* --------------------------------------------------------- eligibility */

    /**
     * A supplier may only quote an RFQ that was routed to them and approved.
     * Returns the routing row, which the quote is stapled to.
     */
    public function assertQuotable(Rfq $rfq, Company $company): RfqCompany
    {
        if ($rfq->status !== RfqStatus::Approved) {
            throw new RuntimeException('Only approved RFQs can be quoted.');
        }

        $routing = RfqCompany::where('rfq_id', $rfq->getKey())
            ->where('company_id', $company->getKey())
            ->first();

        if (! $routing) {
            throw new RuntimeException('This RFQ was not routed to your company.');
        }

        return $routing;
    }

    /* ------------------------------------------------------------- creation */

    /**
     * Open a draft quote for a routed supplier. One active quote per supplier
     * per RFQ is enforced by a partial unique index; this is the friendly guard
     * in front of it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function open(Rfq $rfq, Company $company, array $attributes = []): Quote
    {
        $routing = $this->assertQuotable($rfq, $company);

        $existing = Quote::where('rfq_id', $rfq->getKey())
            ->where('company_id', $company->getKey())
            ->where('status', '!=', QuoteStatus::Withdrawn->value)
            ->first();

        if ($existing) {
            throw new RuntimeException('Your company already has a quote on this RFQ.');
        }

        return Quote::create(array_merge([
            'currency' => $rfq->target_currency ?: 'USD',
            'incoterm' => $rfq->incoterm?->value,
            'status' => QuoteStatus::Draft->value,
        ], $attributes, [
            'rfq_id' => $rfq->getKey(),
            'company_id' => $company->getKey(),
            'rfq_company_id' => $routing->getKey(),
            'reference_code' => $this->references->generate(),
        ]));
    }

    /* ---------------------------------------------------------- transitions */

    public function transition(Quote $quote, QuoteStatus $to, ?Model $actor = null, ?string $reason = null): Quote
    {
        $from = $quote->status;

        if (! in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true)) {
            throw new RuntimeException("Illegal quote transition {$from->value} -> {$to->value}");
        }

        $data = ['status' => $to];

        match ($to) {
            QuoteStatus::Submitted => $data['submitted_at'] = now(),
            QuoteStatus::Viewed => $data['viewed_at'] = now(),
            QuoteStatus::Accepted, QuoteStatus::Declined => $data['decided_at'] = now(),
            default => null,
        };

        if ($to === QuoteStatus::Declined) {
            $data['decline_reason'] = $reason;
        }

        $quote->update($data);

        $log = activity('quote')->performedOn($quote)->event('status_changed')
            ->withProperties(['from' => $from->value, 'to' => $to->value, 'reason' => $reason]);

        if ($actor instanceof User) {
            $log->causedBy($actor);
        }

        $log->log("Quote status -> {$to->value}");

        return $quote->refresh();
    }

    /* -------------------------------------------------------------- supplier */

    /**
     * Submit a draft. Totals are recomputed from the line items first, so the
     * figures the buyer sees can never come from the posted form.
     */
    public function submit(Quote $quote, ?User $actor = null): Quote
    {
        $quote->loadMissing('items', 'rfq', 'company');

        if ($quote->items->isEmpty()) {
            throw new RuntimeException('A quote needs at least one line item before it can be submitted.');
        }

        $this->assertQuotable($quote->rfq, $quote->company);

        $this->recalculate($quote);

        $quote = $this->transition($quote, QuoteStatus::Submitted, $actor);

        if ($quote->routing) {
            $this->leads->setRoutingStatus($quote->routing, RfqCompanyStatus::Responded);
        }

        Mail::to($quote->rfq->buyer_email)->queue(
            new QuoteSubmittedMail($quote->fresh(['items', 'company', 'rfq']), $this->buyerResponsesUrl($quote->rfq)),
        );

        return $quote;
    }

    public function withdraw(Quote $quote, ?User $actor = null, ?string $reason = null): Quote
    {
        return $this->transition($quote, QuoteStatus::Withdrawn, $actor, $reason);
    }

    /**
     * Persist line items and recompute money. Every price the platform shows is
     * derived here; posted subtotals/totals are ignored entirely.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function recalculate(Quote $quote): Quote
    {
        $quote->loadMissing('items');

        foreach ($quote->items as $item) {
            $expected = Quote::lineTotal($item->quantity, $item->unit_price);

            if ((string) $item->line_total !== $expected) {
                $item->forceFill(['line_total' => $expected])->save();
            }
        }

        $quote->load('items');
        $quote->recalculateTotals()->save();

        return $quote;
    }

    /* ----------------------------------------------------------------- buyer */

    /** First buyer view of a submitted quote. Idempotent and never an error. */
    public function markViewed(Quote $quote): Quote
    {
        if ($quote->status !== QuoteStatus::Submitted) {
            return $quote;
        }

        return $this->transition($quote, QuoteStatus::Viewed);
    }

    /**
     * Award the RFQ to one supplier. Accepting is exclusive: every other open
     * quote on the RFQ is declined and the RFQ is closed, all in one
     * transaction, with the rows locked so two concurrent accepts cannot both
     * win.
     */
    public function accept(Quote $quote, ?User $actor = null): Quote
    {
        return DB::transaction(function () use ($quote, $actor) {
            $quote = Quote::whereKey($quote->getKey())->lockForUpdate()->firstOrFail();

            if ($quote->isExpired()) {
                throw new RuntimeException('This quote has expired and can no longer be accepted.');
            }

            $rfq = Rfq::whereKey($quote->rfq_id)->lockForUpdate()->firstOrFail();

            $others = Quote::where('rfq_id', $rfq->getKey())
                ->whereKeyNot($quote->getKey())
                ->open()
                ->lockForUpdate()
                ->get();

            $accepted = $this->transition($quote, QuoteStatus::Accepted, $actor);

            foreach ($others as $other) {
                $this->transition($other, QuoteStatus::Declined, $actor, 'Another quote was accepted for this request.');
            }

            // The RFQ is settled once a quote wins.
            if ($rfq->status !== RfqStatus::Closed) {
                $rfq->update(['status' => RfqStatus::Closed]);

                activity('rfq')->performedOn($rfq)->event('status_changed')
                    ->withProperties(['to' => RfqStatus::Closed->value, 'reason' => 'quote_accepted'])
                    ->log('RFQ status -> closed');
            }

            return $accepted;
        });
    }

    public function decline(Quote $quote, string $reason, ?User $actor = null): Quote
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A decline reason is required.');
        }

        return $this->transition($quote, QuoteStatus::Declined, $actor, trim($reason));
    }

    /* ---------------------------------------------------------------- system */

    /** Lapse every open quote whose validity date has passed. */
    public function expireLapsed(): int
    {
        $expired = 0;

        Quote::open()
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->toDateString())
            ->each(function (Quote $quote) use (&$expired) {
                $this->expire($quote);
                $expired++;
            });

        return $expired;
    }

    public function expire(Quote $quote): Quote
    {
        return $this->transition($quote, QuoteStatus::Expired);
    }

    /* ------------------------------------------------------------- internals */

    private function buyerResponsesUrl(Rfq $rfq): string
    {
        return app(BuyerRfqAccess::class)->responsesUrl($rfq);
    }
}
