<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RfqIncoterm;
use App\Enums\RfqUnit;
use App\Enums\TimberForm;
use App\Exceptions\Api\ConflictException;
use App\Http\Controllers\Controller;
use App\Enums\MessageType;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Quote;
use App\Models\QuoteCounterOffer;
use App\Services\ChatCommerceService;
use App\Services\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * In-thread commerce over token auth — the API counterpart of
 * `Http\Controllers\Public\ChatCommerceController` (the web fallback for the
 * Livewire composer/quotation/negotiation actions).
 *
 * Lives in the SAME any-participant `auth:sanctum` conversation group as
 * `ConversationController` (see routes/api.php and that controller's
 * docblock) — not a buyer-only group — because a supplier is a legitimate
 * actor on half of these actions (issuing is done from the exporter panel,
 * but withdrawing and countering happen from here) and the buyer/supplier
 * split is a property of the THREAD, not of the route. `ChatCommerceService`
 * decides that split; this class only resolves the conversation (a
 * non-participant 404s inside `MessagingService::find()`, matching every
 * other conversation route) and reshapes the result as JSON.
 *
 * Error shape: a `RuntimeException` from the service is a domain refusal
 * ("already accepted", "expired", "still awaiting a reply") and becomes a 409
 * `ConflictException`, mirroring `QuoteController`/`SupplierQuoteController`'s
 * existing convention. `HttpExceptionInterface` (the 403s `assertBuyer()`/
 * `assertSupplier()`/`respondToCounter()` throw for a participant on the
 * wrong side of the thread, and the 404s the belongs-to-thread guards throw)
 * is deliberately re-thrown FIRST — `HttpException` extends `RuntimeException`
 * in Symfony, so without this a role violation would be swallowed by the
 * catch below and turned into an incorrect 409. This is the exact ordering
 * `Public\ChatCommerceController::run()` uses, verified line-for-line.
 */
class ChatCommerceController extends Controller
{
    public function __construct(
        private readonly ChatCommerceService $commerce,
        private readonly MessagingService $messaging,
    ) {}

    /** The in-thread RFQ composer. Buyer only — enforced in the service. */
    public function storeRfq(Request $request, int $id): JsonResponse
    {
        $conversation = $this->messaging->find($request->user(), $id);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:170'],
            'species_text' => ['required', 'string', 'max:180'],
            'form' => ['nullable', 'string', 'in:'.implode(',', array_column(TimberForm::cases(), 'value'))],
            'grade' => ['nullable', 'string', 'max:60'],
            'dimensions' => ['nullable', 'string', 'max:255'],
            'moisture_content' => ['nullable', 'string', 'max:60'],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'unit' => ['required', 'string', 'in:'.implode(',', RfqUnit::values())],
            'incoterm' => ['nullable', 'string', 'in:'.implode(',', array_column(RfqIncoterm::cases(), 'value'))],
            'shipping_port' => ['nullable', 'string', 'max:120'],
            'destination_country_code' => ['nullable', 'string', 'size:2'],
            'deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
            // Anti-spam fields, read by IntakeService exactly as on the wizard
            // and the web composer.
            'website' => ['nullable', 'string', 'max:255'],
            'form_rendered_at' => ['nullable', 'integer'],
        ]);

        $rfq = $this->act(
            $conversation,
            fn () => $this->commerce->createRfqFromChat($conversation, $request->user(), $data),
            'rfq_not_submitted',
        );

        // createRfqFromChat() returns the Rfq, not the card it posted — the
        // card is what a client renders, so fetch it the same way other
        // actions below locate the card a service call just wrote: by its
        // (type, related) pair on this thread.
        $message = $conversation->messages()
            ->where('type', MessageType::RfqReference->value)
            ->where('related_type', $rfq->getMorphClass())
            ->where('related_id', $rfq->getKey())
            ->latest('id')
            ->first();

        $message?->loadMissing('sender', 'senderCompany');

        return response()->json(['data' => $message ? new MessageResource($message) : null], 201);
    }

    public function acceptQuote(Request $request, int $id, Quote $quote): JsonResponse
    {
        $conversation = $this->messaging->find($request->user(), $id);

        $acceptance = $this->act(
            $conversation,
            fn () => $this->commerce->acceptQuotation($conversation, $quote, $request->user(), $request),
            'quote_not_actionable',
        );

        $message = $acceptance->message_id
            ? Message::find($acceptance->message_id)
            : null;

        $message?->loadMissing('sender', 'senderCompany');

        return response()->json(['data' => $message ? new MessageResource($message) : null], 201);
    }

    public function declineQuote(Request $request, int $id, Quote $quote): JsonResponse
    {
        $conversation = $this->messaging->find($request->user(), $id);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->act(
            $conversation,
            fn () => $this->commerce->declineQuotation($conversation, $quote, $request->user(), $data['reason']),
            'quote_not_actionable',
        );

        return $this->latestSystemCard($conversation);
    }

    public function withdrawQuote(Request $request, int $id, Quote $quote): JsonResponse
    {
        $conversation = $this->messaging->find($request->user(), $id);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $this->act(
            $conversation,
            fn () => $this->commerce->withdrawQuotation($conversation, $quote, $request->user(), $data['reason'] ?? null),
            'quote_not_actionable',
        );

        return $this->latestSystemCard($conversation);
    }

    public function counter(Request $request, int $id, Quote $quote): JsonResponse
    {
        $conversation = $this->messaging->find($request->user(), $id);

        $data = $request->validate([
            'unit_price' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
            'unit' => ['nullable', 'string', 'in:'.implode(',', RfqUnit::values())],
            'incoterm' => ['nullable', 'string', 'in:'.implode(',', array_column(RfqIncoterm::cases(), 'value'))],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $offer = $this->act(
            $conversation,
            fn () => $this->commerce->counter($conversation, $quote, $request->user(), $data),
            'counter_offer_not_actionable',
        );

        $message = $conversation->messages()
            ->where('type', MessageType::CounterOffer->value)
            ->where('related_type', $offer->getMorphClass())
            ->where('related_id', $offer->getKey())
            ->latest('id')
            ->first();

        $message?->loadMissing('sender', 'senderCompany');

        return response()->json(['data' => $message ? new MessageResource($message) : null], 201);
    }

    public function respondToCounter(Request $request, int $id, QuoteCounterOffer $offer): JsonResponse
    {
        $conversation = $this->messaging->find($request->user(), $id);

        // The offer must belong to this thread, or the id simply does not
        // resolve — same posture as the web controller and the rest of
        // messaging.
        abort_unless((int) $offer->conversation_id === (int) $conversation->getKey(), 404);

        $data = $request->validate(['decision' => ['required', 'string', 'in:accept,decline']]);

        $this->act(
            $conversation,
            fn () => $this->commerce->respondToCounter($offer, $request->user(), $data['decision']),
            'counter_offer_not_actionable',
        );

        // An accepted counter posts a fresh quotation card; a declined one
        // posts a system note. Either way, the newest card in the thread is
        // the result of this action.
        return $this->latestSystemCard($conversation);
    }

    /* -------------------------------------------------------------- plumbing */

    /**
     * Run a commerce action, translating a domain refusal into a 409 and
     * re-throwing an authorisation failure unchanged.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function act(Conversation $conversation, callable $action, string $code)
    {
        try {
            return $action();
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new ConflictException($e->getMessage(), $code, $e);
        }
    }

    /** The newest card in the thread, for actions whose result is a status change rather than a fresh card. */
    private function latestSystemCard(Conversation $conversation): JsonResponse
    {
        $message = $conversation->messages()->latest('id')->first();
        $message?->loadMissing('sender', 'senderCompany');

        return response()->json(['data' => $message ? new MessageResource($message) : null]);
    }
}
