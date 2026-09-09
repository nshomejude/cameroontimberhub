<?php

namespace App\Services;

use App\Domain\Trade\Commands\DeclineQuoteCommand;
use App\Domain\Trade\Commands\WithdrawQuoteCommand;
use App\Enums\CounterOfferStatus;
use App\Enums\MessageType;
use App\Enums\QuoteStatus;
use App\Enums\RfqUnit;
use App\Models\ContractAcceptance;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Quote;
use App\Models\QuoteCounterOffer;
use App\Models\Rfq;
use App\Models\User;
use App\Support\Bus\CommandBus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Commerce inside a conversation: RFQ composer, quotation card, negotiation,
 * and the recorded acceptance of terms.
 *
 * This is the Phase 2 counterpart to MessagingService and it holds exactly one
 * kind of knowledge: *who may do what, on which side of a thread*. Money moves
 * through QuoteService and OrderService untouched; RFQs are written through
 * IntakeService untouched. Nothing here re-implements a state machine.
 *
 * Two-sided authorisation
 * -----------------------
 * Every method takes the conversation and resolves the actor's side through
 * MessagingService (which 404s a non-participant before any attribute of the
 * thread is read). Then:
 *
 *   - only the BUYER may send an RFQ, accept, decline, or accept/decline a
 *     supplier's counter;
 *   - only the SUPPLIER may issue or withdraw a quotation;
 *   - either side may counter, but only the side that *owes an answer* on the
 *     live round may respond to it.
 *
 * Those are not view-layer conditions. The view merely mirrors them.
 */
class ChatCommerceService
{
    /** Rounds allowed on one quote before the parties must take it offline. */
    public const MAX_COUNTER_ROUNDS = 20;

    public function __construct(
        private readonly MessagingService $messaging,
        private readonly IntakeService $intake,
        private readonly QuoteService $quotes,
        private readonly CommandBus $commands,
    ) {}

    /* ------------------------------------------------------------- parties */

    /**
     * Which side of this thread the actor is on. Authorises first, so a
     * non-participant 404s here rather than being told "neither".
     */
    public function party(Conversation $conversation, User $user): string
    {
        $this->messaging->authorize($user, $conversation);

        return (int) $conversation->user_id === (int) $user->getKey()
            ? ConversationParticipant::ROLE_BUYER
            : ConversationParticipant::ROLE_SUPPLIER;
    }

    public function isBuyer(Conversation $conversation, User $user): bool
    {
        return $this->party($conversation, $user) === ConversationParticipant::ROLE_BUYER;
    }

    /**
     * 403, not 404: at this point the actor is a proven participant, so the
     * thread's existence is not a secret from them. Only their *role* is wrong,
     * and saying so is the honest and more useful answer.
     */
    public function assertBuyer(Conversation $conversation, User $user): void
    {
        abort_unless($this->isBuyer($conversation, $user), 403, 'Only the buyer on this conversation can do that.');
    }

    public function assertSupplier(Conversation $conversation, User $user): void
    {
        abort_if($this->isBuyer($conversation, $user), 403, 'Only the supplier on this conversation can do that.');
    }

    /* ---------------------------------------------------------------- RFQ */

    /**
     * The in-thread RFQ composer (mockup: "REQUEST FOR QUOTE (RFQ)").
     *
     * Deliberately delegates to IntakeService::createRfq() — the single RFQ
     * write path — so reference generation, risk scoring, the owner backfill
     * and the verification mail all behave exactly as they do on the public
     * wizard. The honeypot/min-time check runs first, from the same service.
     *
     * The verification gate
     * ---------------------
     * It is ENFORCED, and satisfied without a second email round-trip when —
     * and only when — the platform has already verified this exact address:
     * the buyer is authenticated, `users.email_verified_at` is set, and the
     * RFQ's `buyer_email` is that same verified address. In that case the thing
     * the gate exists to prove ("a human controls this mailbox") is already
     * proven, by this platform, and we mark it verified through
     * IntakeService::verifyRfq() rather than inventing a new column.
     *
     * If the account's own email is unverified, or the buyer types a different
     * address, the RFQ stays unverified and the signed confirmation link is the
     * only way through — identical to the public path. The gate is never
     * skipped merely because someone is logged in.
     *
     * @param  array<string, mixed>  $data
     */
    public function createRfqFromChat(Conversation $conversation, User $buyer, array $data): Rfq
    {
        $this->assertBuyer($conversation, $buyer);

        if ($this->intake->honeypotTripped($data + ['buyer_email' => $buyer->email])) {
            throw new RuntimeException('This request could not be submitted.');
        }

        $conversation->loadMissing('company');

        $species = trim((string) ($data['species_text'] ?? ''));
        $title = Str::limit(trim((string) ($data['title'] ?? '')) ?: 'Quote request'.($species ? ' — '.$species : ''), 170, '');

        $rfq = $this->intake->createRfq(
            [
                'title' => $title,
                'buyer_name' => $buyer->name,
                'buyer_email' => strtolower(trim($buyer->email)),
                'incoterm' => $data['incoterm'] ?? null,
                'shipping_port' => $data['shipping_port'] ?? null,
                'destination_country_code' => isset($data['destination_country_code'])
                    ? strtoupper((string) $data['destination_country_code'])
                    : null,
                'deadline' => $data['deadline'] ?? null,
                'notes' => $data['notes'] ?? null,
            ],
            [[
                'species_text' => $species ?: null,
                'form' => $data['form'] ?? null,
                'grade' => $data['grade'] ?? null,
                'dimensions' => $data['dimensions'] ?? null,
                'moisture_content' => $data['moisture_content'] ?? null,
                'quantity' => $data['quantity'] ?? null,
                'unit' => $data['unit'] ?? null,
            ]],
            'chat',
        );

        // See the docblock: satisfied, never skipped.
        if ($this->emailAlreadyVerifiedFor($buyer, $rfq)) {
            $this->intake->verifyRfq($rfq);
            $rfq->refresh();
        }

        DB::transaction(function () use ($conversation, $buyer, $rfq) {
            // Record the RFQ as this thread's context when the thread has none
            // yet, so later quotation cards can find it without a request id.
            if ($conversation->rfq_id === null) {
                $conversation->forceFill(['rfq_id' => $rfq->getKey()])->save();
            }

            $this->messaging->postRfqReference($conversation, $buyer, $rfq);
        });

        activity('rfq')->performedOn($rfq)->causedBy($buyer)->event('created')
            ->withProperties(['source' => 'chat', 'conversation_id' => $conversation->getKey()])
            ->log('RFQ submitted from a conversation');

        return $rfq->refresh();
    }

    /**
     * The gate is satisfied only when THIS platform verified THIS address.
     * A different address, or an unverified account, keeps the email round-trip.
     */
    public function emailAlreadyVerifiedFor(User $buyer, Rfq $rfq): bool
    {
        return $buyer->email_verified_at !== null
            && strtolower(trim((string) $buyer->email)) === strtolower(trim((string) $rfq->buyer_email));
    }

    /* ---------------------------------------------------------- quotation */

    /**
     * Post an existing, buyer-visible quote into the thread as a card.
     *
     * The supplier issues; the buyer never can. The quote must belong to the
     * company on the other side of this very thread, so a staff member who sits
     * on two companies cannot post company A's quote into company B's
     * conversation. Idempotent per quote revision — re-posting the same quote
     * returns the existing card rather than spamming the thread.
     */
    public function issueQuotation(Conversation $conversation, Quote $quote, User $supplier): Message
    {
        $this->assertSupplier($conversation, $supplier);

        if ((int) $quote->company_id !== (int) $conversation->company_id) {
            abort(403, 'That quote belongs to another company.');
        }

        if (! in_array($quote->status->value, QuoteStatus::buyerVisible(), true)) {
            throw new RuntimeException('Only a submitted quote can be shared in a conversation.');
        }

        $existing = $conversation->messages()
            ->where('type', MessageType::Quotation->value)
            ->where('related_type', $quote->getMorphClass())
            ->where('related_id', $quote->getKey())
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($conversation, $quote, $supplier) {
            if ($conversation->quote_id === null) {
                $conversation->forceFill([
                    'quote_id' => $quote->getKey(),
                    'rfq_id' => $conversation->rfq_id ?? $quote->rfq_id,
                ])->save();
            }

            return $this->messaging->postQuotation($conversation, $supplier, $quote);
        });
    }

    /**
     * Buyer accepts a quotation from the thread.
     *
     * Order of operations matters. The acceptance record is written FIRST,
     * inside the same transaction, against the terms snapshot taken before any
     * status moved — so the hash records what the buyer was actually looking
     * at, not what the row became a millisecond later. Then QuoteService::
     * accept() runs unchanged and does what it has always done: lock, decline
     * the siblings, close the RFQ, mint the order, issue the receipt.
     *
     * Everything is one rollback boundary, so there is no state in which a
     * signature-shaped record exists without the order it accepted.
     */
    public function acceptQuotation(Conversation $conversation, Quote $quote, User $buyer, ?Request $request = null): ContractAcceptance
    {
        $this->assertBuyer($conversation, $buyer);
        $this->assertQuoteBelongsToThread($conversation, $quote);

        return DB::transaction(function () use ($conversation, $quote, $buyer, $request) {
            $locked = Quote::whereKey($quote->getKey())->lockForUpdate()->firstOrFail();

            $this->assertActionable($locked);

            // A pending negotiation round is an open question. Accepting the
            // underlying quote while one is live would leave the ledger saying
            // "awaiting reply" forever, so the round is closed first.
            $locked->counterOffers()->pending()->update([
                'status' => CounterOfferStatus::Superseded->value,
                'responded_by_user_id' => $buyer->getKey(),
                'responded_at' => now(),
            ]);

            $terms = $this->messaging->quotationTerms($locked);

            $acceptance = ContractAcceptance::create([
                'quote_id' => $locked->getKey(),
                'conversation_id' => $conversation->getKey(),
                'accepted_by_user_id' => $buyer->getKey(),
                'accepted_by_name' => $buyer->name,
                'accepted_by_email' => $buyer->email,
                'party' => ConversationParticipant::ROLE_BUYER,
                'accepted_at' => now(),
                'ip_address' => $request?->ip() ?? request()->ip(),
                'user_agent' => Str::limit((string) ($request?->userAgent() ?? request()->userAgent()), 500, ''),
                'terms_hash' => ContractAcceptance::hashTerms($terms),
                'terms' => $terms,
            ]);

            // Unchanged Phase 1 path: siblings declined, RFQ closed, order and
            // receipt created, all under the same lock.
            $accepted = $this->quotes->accept($locked, $buyer);

            $card = $this->messaging->postContractAcceptance($conversation, $acceptance->setRelation('quote', $accepted));
            $acceptance->forceFill(['message_id' => $card->getKey()])->save();

            $order = $accepted->order()->first();

            if ($order) {
                $conversation->forceFill(['order_id' => $order->getKey()])->save();
                $this->messaging->postOrderReference($conversation, null, $order);
            }

            return $acceptance->refresh();
        });
    }

    /** Buyer declines a quotation from the thread. Supplier may not. */
    public function declineQuotation(Conversation $conversation, Quote $quote, User $buyer, string $reason): Quote
    {
        $this->assertBuyer($conversation, $buyer);
        $this->assertQuoteBelongsToThread($conversation, $quote);

        return DB::transaction(function () use ($conversation, $quote, $buyer, $reason) {
            $locked = Quote::whereKey($quote->getKey())->lockForUpdate()->firstOrFail();

            $this->assertActionable($locked);

            $declined = $this->commands->dispatch(new DeclineQuoteCommand(
                quoteId: $locked->getKey(),
                reason: $reason,
                actingUserId: $buyer->getKey(),
            ));

            $this->messaging->postSystem(
                $conversation,
                'Quotation '.$declined->reference_code.' was declined by the buyer.',
            );

            return $declined;
        });
    }

    /** Supplier withdraws their own quotation. Buyer may not. */
    public function withdrawQuotation(Conversation $conversation, Quote $quote, User $supplier, ?string $reason = null): Quote
    {
        $this->assertSupplier($conversation, $supplier);
        $this->assertQuoteBelongsToThread($conversation, $quote);

        return DB::transaction(function () use ($conversation, $quote, $supplier, $reason) {
            $locked = Quote::whereKey($quote->getKey())->lockForUpdate()->firstOrFail();

            $this->assertActionable($locked);

            $withdrawn = $this->commands->dispatch(new WithdrawQuoteCommand(
                quoteId: $locked->getKey(),
                reason: $reason,
                actingUserId: $supplier->getKey(),
            ));

            $this->messaging->postSystem(
                $conversation,
                'Quotation '.$withdrawn->reference_code.' was withdrawn by the supplier.',
            );

            return $withdrawn;
        });
    }

    /* -------------------------------------------------------- negotiation */

    /**
     * Open (or answer with) a counter-offer.
     *
     * Either side may counter a live quote. A pending round from the other side
     * is marked superseded in the same transaction, which is what "Counter
     * Again" in the mockup actually means — and the partial unique index on
     * (quote_id) WHERE status='pending' makes that atomic rather than hopeful.
     *
     * Restricted to single-line quotes on purpose: a counter carries ONE unit
     * price, and splitting it back across several lines would mean inventing
     * per-line numbers neither party proposed.
     *
     * @param  array<string, mixed>  $data
     */
    public function counter(Conversation $conversation, Quote $quote, User $actor, array $data): QuoteCounterOffer
    {
        $party = $this->party($conversation, $actor);
        $this->assertQuoteBelongsToThread($conversation, $quote);

        return DB::transaction(function () use ($conversation, $quote, $actor, $data, $party) {
            $locked = Quote::whereKey($quote->getKey())->lockForUpdate()->firstOrFail();

            $this->assertNegotiable($locked);

            $locked->loadMissing('items');

            if ($locked->items->count() !== 1) {
                throw new RuntimeException('A counter-offer can only be made on a single-line quotation.');
            }

            $line = $locked->items->first();

            $pending = $locked->counterOffers()->pending()->lockForUpdate()->first();

            if ($pending && $pending->party === $party) {
                throw new RuntimeException('Your counter-offer is still awaiting a reply.');
            }

            if ($locked->counterOffers()->count() >= self::MAX_COUNTER_ROUNDS) {
                throw new RuntimeException('This negotiation has reached its round limit.');
            }

            if ($pending) {
                $pending->forceFill([
                    'status' => CounterOfferStatus::Superseded->value,
                    'responded_by_user_id' => $actor->getKey(),
                    'responded_at' => now(),
                ])->save();
            }

            $quantity = $data['quantity'] ?? $line->quantity;
            $unitPrice = $data['unit_price'];

            $offer = QuoteCounterOffer::create([
                'quote_id' => $locked->getKey(),
                'conversation_id' => $conversation->getKey(),
                'proposed_by_user_id' => $actor->getKey(),
                'party' => $party,
                'currency' => $locked->currency->value,
                'quantity' => $quantity,
                'unit' => $data['unit'] ?? $line->unit?->value,
                'unit_price' => $unitPrice,
                // Derived here, never posted: the totals a buyer reads are
                // always computed by us from quantity x unit price.
                'total_amount' => QuoteCounterOffer::total($quantity, $unitPrice),
                'incoterm' => $data['incoterm'] ?? $locked->incoterm?->value,
                'lead_time_days' => $data['lead_time_days'] ?? $locked->lead_time_days,
                'payment_terms' => $data['payment_terms'] ?? $locked->payment_terms,
                'note' => isset($data['note']) ? Str::limit(trim((string) $data['note']), 1000, '') : null,
                'status' => CounterOfferStatus::Pending->value,
            ]);

            $this->messaging->postCounterOffer($conversation, $actor, $offer->setRelation('quote', $locked));

            activity('quote')->performedOn($locked)->causedBy($actor)->event('counter_offered')
                ->withProperties([
                    'counter_offer_id' => $offer->getKey(),
                    'party' => $party,
                    'unit_price' => (string) $offer->unit_price,
                ])
                ->log('Counter-offer proposed');

            return $offer;
        });
    }

    /**
     * Answer the live round. Only the side that owes the answer may call this,
     * which is what stops self-acceptance: the proposer is never the awaiting
     * party.
     */
    public function respondToCounter(QuoteCounterOffer $offer, User $actor, string $decision): QuoteCounterOffer
    {
        $conversation = $offer->conversation;
        $party = $this->party($conversation, $actor);

        return DB::transaction(function () use ($offer, $actor, $party, $conversation, $decision) {
            $locked = QuoteCounterOffer::whereKey($offer->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw new RuntimeException('This counter-offer has already been answered.');
            }

            if ($locked->awaitingParty() !== $party) {
                abort(403, 'Only the other party can respond to this counter-offer.');
            }

            $quote = Quote::whereKey($locked->quote_id)->lockForUpdate()->firstOrFail();

            $this->assertNegotiable($quote);

            if ($decision === 'decline') {
                $locked->forceFill([
                    'status' => CounterOfferStatus::Declined->value,
                    'responded_by_user_id' => $actor->getKey(),
                    'responded_at' => now(),
                ])->save();

                $this->messaging->postSystem(
                    $conversation,
                    'The counter-offer on quotation '.$quote->reference_code.' was declined.',
                );

                return $locked->refresh();
            }

            if ($decision !== 'accept') {
                throw new RuntimeException('Unknown counter-offer decision.');
            }

            $revision = $this->reviseQuote($quote, $locked, $actor);

            $locked->forceFill([
                'status' => CounterOfferStatus::Accepted->value,
                'responded_by_user_id' => $actor->getKey(),
                'responded_at' => now(),
                'resulting_quote_id' => $revision->getKey(),
            ])->save();

            $this->messaging->postQuotation($conversation, $actor, $revision);

            $conversation->forceFill(['quote_id' => $revision->getKey()])->save();

            return $locked->refresh();
        });
    }

    /**
     * Turn an agreed round into a revised quote.
     *
     * A revision is a NEW Quote, not an edit. The old figures are the evidence
     * of what was countered, and the partial unique index allows exactly one
     * non-withdrawn quote per (rfq, company) — so the predecessor is withdrawn
     * and linked, and the thread renders it as "Revised" rather than as an
     * abandoned offer.
     *
     * Always issued as the supplier's offer, whichever side accepted, because a
     * quote is by definition the supplier's price.
     */
    private function reviseQuote(Quote $quote, QuoteCounterOffer $offer, User $actor): Quote
    {
        $quote->loadMissing(['items', 'rfq', 'company']);

        $line = $quote->items->first();

        if ($line === null) {
            throw new RuntimeException('A quotation with no line items cannot be revised.');
        }

        $this->quotes->withdraw($quote, $actor, 'Superseded by a negotiated revision.');

        $revision = $this->quotes->open($quote->rfq, $quote->company, [
            'currency' => $quote->currency->value,
            'incoterm' => $offer->incoterm?->value ?? $quote->incoterm?->value,
            'lead_time_days' => $offer->lead_time_days ?? $quote->lead_time_days,
            'validity_days' => $quote->validity_days,
            'valid_until' => $quote->validity_days ? now()->addDays($quote->validity_days)->toDateString() : $quote->valid_until?->toDateString(),
            'payment_terms' => $offer->payment_terms ?? $quote->payment_terms,
            'notes' => $quote->notes,
            'shipping_amount' => $quote->shipping_amount,
            'tax_amount' => $quote->tax_amount,
            'revision' => (int) ($quote->revision ?? 1) + 1,
            'supersedes_quote_id' => $quote->getKey(),
        ]);

        $quantity = $offer->quantity ?? $line->quantity;

        $revision->items()->create([
            'rfq_item_id' => $line->rfq_item_id,
            'species_id' => $line->species_id,
            'description' => $line->description,
            'form' => $line->form?->value,
            'grade' => $line->grade,
            'dimensions' => $line->dimensions,
            'quantity' => $quantity,
            'unit' => ($offer->unit ?? $line->unit)?->value,
            'unit_price' => $offer->unit_price,
            'line_total' => Quote::lineTotal($quantity, $offer->unit_price),
        ]);

        // submit() recomputes every total from the line items, so the revised
        // figures the buyer sees are ours, not the counter-offer's arithmetic.
        return $this->quotes->submit($revision->refresh(), $actor);
    }

    /* ------------------------------------------------------------- guards */

    /** The quote must be the supplier's on this very thread. */
    private function assertQuoteBelongsToThread(Conversation $conversation, Quote $quote): void
    {
        abort_unless((int) $quote->company_id === (int) $conversation->company_id, 404);
    }

    /**
     * The single "can this still be acted on" test, in front of the DB
     * constraints rather than instead of them.
     */
    private function assertActionable(Quote $quote): void
    {
        if ($quote->isExpired()) {
            throw new RuntimeException('This quotation has expired and can no longer be actioned.');
        }

        if (! $quote->status->isOpen()) {
            throw new RuntimeException('This quotation is '.strtolower($quote->status->label()).' and can no longer be actioned.');
        }
    }

    private function assertNegotiable(Quote $quote): void
    {
        $this->assertActionable($quote);
    }

    /* -------------------------------------------------------- convenience */

    /** Units a chat RFQ may be expressed in — the DB's list, not a new one. */
    public function unitOptions(): array
    {
        return RfqUnit::options();
    }
}
