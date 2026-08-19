<?php

namespace App\Livewire\Messaging;

use App\Enums\RfqIncoterm;
use App\Enums\RfqUnit;
use App\Enums\TimberForm;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Quote;
use App\Models\QuoteCounterOffer;
use App\Services\ChatCommerceService;
use App\Services\MessagingService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * One conversation (mockup: "Mobile message thread").
 *
 * Near-real-time is `wire:poll.10s` on the message list — a 10 second beat is
 * fast enough that a live negotiation does not feel dead, and cheap enough for
 * a Postgres-backed app with no websocket layer (no Pusher/Reverb/Echo here by
 * requirement). The poll re-renders only this component.
 *
 * Not rendered, because none of it is real data: presence ("Online"), typing
 * indicators, and voice notes. Read state IS real — it is derived from the
 * counterparty's `last_read_at` — so the double-tick in the mockup is honest
 * and is kept.
 */
class Thread extends Component
{
    public int $conversationId;

    /** Where the back arrow goes on this surface. */
    public string $backUrl = '';

    /**
     * Optional plain-HTTP POST target for the composer, so it degrades to a
     * native form submit when JavaScript is off. Only the buyer surface has
     * such a route; the exporter panel is Filament, so it is empty there.
     */
    public string $postAction = '';

    public string $body = '';

    public ?int $replyToId = null;

    /** Id of the message whose action sheet is open (null = none). */
    public ?int $openActionsFor = null;

    public function mount(int $conversationId, string $backUrl = '', string $postAction = ''): void
    {
        // Resolves through the participant scope, so a foreign id 404s here
        // rather than anywhere further in.
        $conversation = app(MessagingService::class)->find(auth()->user(), $conversationId);

        $this->conversationId = (int) $conversation->getKey();
        $this->backUrl = $backUrl;
        $this->postAction = $postAction;
    }

    public function conversation(): Conversation
    {
        return app(MessagingService::class)->find(auth()->user(), $this->conversationId);
    }

    public function send(): void
    {
        $this->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $user = auth()->user();

        // Livewire posts do not pass through route middleware, so the same
        // limiter the HTTP fallback route uses is enforced here explicitly.
        $key = 'msg-send:'.$user->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($key, 20)) {
            $this->addError('body', 'You are sending messages too quickly. Try again in a moment.');

            return;
        }

        RateLimiter::hit($key, 60);

        $messaging = app(MessagingService::class);
        $conversation = $messaging->find($user, $this->conversationId);

        $messaging->post(
            $conversation,
            $user,
            $this->body,
            $this->replyToId ? Message::find($this->replyToId) : null,
        );

        $this->reset('body', 'replyToId', 'openActionsFor');
    }

    /* ------------------------------------------------------ chat commerce */

    /**
     * The in-thread commerce actions.
     *
     * Every one of them re-resolves the conversation through MessagingService
     * (so a tampered `conversationId` 404s), re-checks the per-account limiter
     * (Livewire posts bypass route middleware), and then hands off to
     * ChatCommerceService, which owns the buyer/supplier rule. Nothing about
     * who may do what is decided in this class or in the view.
     */

    /** Whether the inline RFQ composer card is open. */
    public bool $showRfqForm = false;

    /** Quote id whose counter-offer form is open, if any. */
    public ?int $counteringQuoteId = null;

    /** @var array<string, mixed> */
    public array $rfqForm = [
        'species_text' => '',
        'form' => '',
        'grade' => '',
        'dimensions' => '',
        'moisture_content' => '',
        'quantity' => '',
        'unit' => 'm3',
        'incoterm' => '',
        'shipping_port' => '',
        'deadline' => '',
        'notes' => '',
    ];

    /** @var array<string, mixed> */
    public array $counterForm = [
        'unit_price' => '',
        'quantity' => '',
        'payment_terms' => '',
        'lead_time_days' => '',
        'note' => '',
    ];

    public function toggleRfqForm(): void
    {
        $this->showRfqForm = ! $this->showRfqForm;
        $this->resetErrorBag();
    }

    public function submitRfq(): void
    {
        $this->validate([
            'rfqForm.species_text' => ['required', 'string', 'max:180'],
            'rfqForm.quantity' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'rfqForm.unit' => ['required', 'string', 'in:'.implode(',', RfqUnit::values())],
            'rfqForm.form' => ['nullable', 'string', 'in:'.implode(',', array_column(TimberForm::cases(), 'value'))],
            'rfqForm.incoterm' => ['nullable', 'string', 'in:'.implode(',', array_column(RfqIncoterm::cases(), 'value'))],
            'rfqForm.grade' => ['nullable', 'string', 'max:60'],
            'rfqForm.dimensions' => ['nullable', 'string', 'max:255'],
            'rfqForm.moisture_content' => ['nullable', 'string', 'max:60'],
            'rfqForm.shipping_port' => ['nullable', 'string', 'max:120'],
            'rfqForm.deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'rfqForm.notes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'rfqForm.species_text' => 'species',
            'rfqForm.quantity' => 'quantity',
            'rfqForm.unit' => 'unit',
        ]);

        if (! $this->allow('chat-rfq', 12, 3600, 'rfqForm.species_text')) {
            return;
        }

        $this->commerce(function (ChatCommerceService $commerce, $conversation, $user) {
            $commerce->createRfqFromChat($conversation, $user, array_filter(
                $this->rfqForm,
                fn ($value) => $value !== '' && $value !== null,
            ));

            $this->reset('rfqForm', 'showRfqForm');
        }, 'rfqForm.species_text');
    }

    public function acceptQuote(int $quoteId): void
    {
        if (! $this->allow('chat-decision', 10, 60)) {
            return;
        }

        $this->commerce(fn (ChatCommerceService $commerce, $conversation, $user) => $commerce->acceptQuotation(
            $conversation,
            $this->threadQuote($quoteId),
            $user,
            request(),
        ));
    }

    public function declineQuote(int $quoteId, string $reason = 'Declined from the conversation.'): void
    {
        if (! $this->allow('chat-decision', 10, 60)) {
            return;
        }

        $this->commerce(fn (ChatCommerceService $commerce, $conversation, $user) => $commerce->declineQuotation(
            $conversation,
            $this->threadQuote($quoteId),
            $user,
            $reason,
        ));
    }

    public function withdrawQuote(int $quoteId): void
    {
        if (! $this->allow('chat-decision', 10, 60)) {
            return;
        }

        $this->commerce(fn (ChatCommerceService $commerce, $conversation, $user) => $commerce->withdrawQuotation(
            $conversation,
            $this->threadQuote($quoteId),
            $user,
        ));
    }

    public function openCounter(int $quoteId): void
    {
        $this->counteringQuoteId = $quoteId;
        $this->reset('counterForm');
        $this->resetErrorBag();
    }

    public function cancelCounter(): void
    {
        $this->counteringQuoteId = null;
    }

    public function submitCounter(): void
    {
        $this->validate([
            'counterForm.unit_price' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'counterForm.quantity' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
            'counterForm.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'counterForm.payment_terms' => ['nullable', 'string', 'max:255'],
            'counterForm.note' => ['nullable', 'string', 'max:1000'],
        ], [], ['counterForm.unit_price' => 'unit price']);

        if (! $this->allow('chat-decision', 10, 60, 'counterForm.unit_price')) {
            return;
        }

        $quoteId = (int) $this->counteringQuoteId;

        $this->commerce(function (ChatCommerceService $commerce, $conversation, $user) use ($quoteId) {
            $commerce->counter($conversation, $this->threadQuote($quoteId), $user, array_filter(
                $this->counterForm,
                fn ($value) => $value !== '' && $value !== null,
            ));

            $this->reset('counterForm', 'counteringQuoteId');
        }, 'counterForm.unit_price');
    }

    public function respondToCounter(int $offerId, string $decision): void
    {
        if (! $this->allow('chat-decision', 10, 60)) {
            return;
        }

        $this->commerce(function (ChatCommerceService $commerce, $conversation, $user) use ($offerId, $decision) {
            // Scoped lookup: an offer id from another thread never resolves.
            $offer = QuoteCounterOffer::where('conversation_id', $conversation->getKey())
                ->whereKey($offerId)
                ->firstOrFail();

            $commerce->respondToCounter($offer->setRelation('conversation', $conversation), $user, $decision);
        });
    }

    /* --------------------------------------------------- commerce plumbing */

    /**
     * Run a commerce action against the freshly re-resolved conversation.
     *
     * A RuntimeException is a domain refusal ("expired", "already accepted") —
     * the expected outcome of acting on a card that went stale while it was on
     * screen — so it becomes an inline error rather than a 500. Authorisation
     * failures are HttpExceptions and are left to propagate as 403/404.
     */
    private function commerce(callable $action, string $errorField = 'body'): void
    {
        $user = auth()->user();
        $conversation = app(MessagingService::class)->find($user, $this->conversationId);

        try {
            $action(app(ChatCommerceService::class), $conversation, $user);
        } catch (HttpExceptionInterface|RecordsNotFoundException $e) {
            // Symfony's HttpException extends RuntimeException. Without this
            // first catch, a 403 from the role gate would be demoted to an
            // inline validation message and the action would look merely
            // "rejected" rather than forbidden.
            throw $e;
        } catch (RuntimeException $e) {
            $this->addError($errorField, $e->getMessage());
        }
    }

    /**
     * A quote id, resolved only if it belongs to the company on this thread.
     * Never a bare Quote::find().
     */
    private function threadQuote(int $quoteId): Quote
    {
        $conversation = app(MessagingService::class)->find(auth()->user(), $this->conversationId);

        // abort(404) rather than firstOrFail(): a quote id that belongs to
        // another company must be indistinguishable from one that does not
        // exist, and an explicit HTTP 404 says that identically on the plain
        // route and inside a Livewire round trip.
        return Quote::where('company_id', $conversation->company_id)
            ->whereKey($quoteId)
            ->firstOr(fn () => abort(404));
    }

    /** The same per-account budget the routes enforce, applied to Livewire. */
    private function allow(string $bucket, int $attempts, int $decay, string $errorField = 'body'): bool
    {
        $key = $bucket.':'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            $this->addError($errorField, 'Too many attempts. Try again in a moment.');

            return false;
        }

        RateLimiter::hit($key, $decay);

        return true;
    }

    /* ------------------------------------------------------- message actions */

    public function openActions(int $messageId): void
    {
        $this->openActionsFor = $messageId;
    }

    public function closeActions(): void
    {
        $this->openActionsFor = null;
    }

    public function reply(int $messageId): void
    {
        $this->replyToId = $this->message($messageId)->getKey();
        $this->openActionsFor = null;
    }

    public function cancelReply(): void
    {
        $this->replyToId = null;
    }

    /** Delete your own prose. Soft delete: the row survives for audit. */
    public function deleteMessage(int $messageId): void
    {
        app(MessagingService::class)->delete($this->message($messageId), auth()->user());

        $this->openActionsFor = null;
    }

    /** Scoped lookup: a message id from another thread never resolves. */
    private function message(int $messageId): Message
    {
        $conversation = app(MessagingService::class)->find(auth()->user(), $this->conversationId);

        return $conversation->messages()->whereKey($messageId)->firstOrFail();
    }

    public function render(): View
    {
        $user = auth()->user();
        $messaging = app(MessagingService::class);

        $conversation = $messaging->find($user, $this->conversationId);
        $conversation->load(['company', 'user', 'product', 'order', 'participants']);

        $messages = $messaging->messages($conversation);

        // Opening (or polling) a thread you are looking at is reading it.
        $messaging->markRead($conversation, $user);

        return view('livewire.messaging.thread', [
            'user' => $user,
            'conversation' => $conversation,
            'messages' => $messages,
            'messaging' => $messaging,
            'replyTo' => $this->replyToId ? $messages->firstWhere('id', $this->replyToId) : null,
            // Which side this viewer is on, resolved once and handed to every
            // card, so no partial recomputes it (and none of them can get it
            // wrong in a way that would show the other side's buttons).
            'isBuyer' => (int) $conversation->user_id === (int) $user->getKey(),
            'unitOptions' => RfqUnit::options(),
            'formOptions' => collect(TimberForm::cases())->mapWithKeys(fn (TimberForm $f) => [$f->value => $f->label()])->all(),
            'incotermOptions' => collect(RfqIncoterm::cases())->mapWithKeys(fn (RfqIncoterm $i) => [$i->value => $i->label()])->all(),
        ]);
    }
}
