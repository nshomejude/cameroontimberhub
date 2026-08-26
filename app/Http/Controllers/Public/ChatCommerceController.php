<?php

namespace App\Http\Controllers\Public;

use App\Enums\RfqIncoterm;
use App\Enums\RfqUnit;
use App\Enums\TimberForm;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Quote;
use App\Models\QuoteCounterOffer;
use App\Services\ChatCommerceService;
use App\Services\MessagingService;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Plain-HTTP counterpart to the in-thread commerce actions.
 *
 * Every route here is POST behind the session CSRF token and the `auth`
 * middleware — there is deliberately no GET that changes anything, so a quote
 * cannot be accepted by an image tag, a link preview crawler or a prefetch.
 *
 * These exist for two reasons: the thread must keep working with JavaScript
 * off (the Phase 1 composer already does), and a plain route is the honest
 * place to prove the buyer/supplier boundary in a test, because it exercises
 * the same ChatCommerceService gate the Livewire actions call.
 *
 * The role checks live in ChatCommerceService, never here. This class only
 * validates shapes and turns a domain RuntimeException into a flash message.
 */
class ChatCommerceController extends Controller
{
    public function __construct(
        private readonly ChatCommerceService $commerce,
        private readonly MessagingService $messaging,
    ) {}

    /** The in-thread RFQ composer. Buyer only; enforced in the service. */
    public function storeRfq(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->messaging->authorize($request->user(), $conversation);

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
            // Anti-spam fields, read by IntakeService exactly as on the wizard.
            'website' => ['nullable', 'string', 'max:255'],
            'form_rendered_at' => ['nullable', 'integer'],
        ]);

        return $this->run($conversation, fn () => $this->commerce->createRfqFromChat(
            $conversation,
            $request->user(),
            $data + $request->only(['website', 'form_rendered_at']),
        ), 'Request for quote sent.');
    }

    public function acceptQuote(Request $request, Conversation $conversation, Quote $quote): RedirectResponse
    {
        $this->messaging->authorize($request->user(), $conversation);

        return $this->run(
            $conversation,
            fn () => $this->commerce->acceptQuotation($conversation, $quote, $request->user(), $request),
            'Quotation accepted. Your order has been created.',
        );
    }

    public function declineQuote(Request $request, Conversation $conversation, Quote $quote): RedirectResponse
    {
        $this->messaging->authorize($request->user(), $conversation);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->run(
            $conversation,
            fn () => $this->commerce->declineQuotation($conversation, $quote, $request->user(), $data['reason']),
            'Quotation declined.',
        );
    }

    public function withdrawQuote(Request $request, Conversation $conversation, Quote $quote): RedirectResponse
    {
        $this->messaging->authorize($request->user(), $conversation);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->run(
            $conversation,
            fn () => $this->commerce->withdrawQuotation($conversation, $quote, $request->user(), $data['reason'] ?? null),
            'Quotation withdrawn.',
        );
    }

    public function counter(Request $request, Conversation $conversation, Quote $quote): RedirectResponse
    {
        $this->messaging->authorize($request->user(), $conversation);

        $data = $request->validate([
            'unit_price' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
            'unit' => ['nullable', 'string', 'in:'.implode(',', RfqUnit::values())],
            'incoterm' => ['nullable', 'string', 'in:'.implode(',', array_column(RfqIncoterm::cases(), 'value'))],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->run(
            $conversation,
            fn () => $this->commerce->counter($conversation, $quote, $request->user(), $data),
            'Counter-offer sent.',
        );
    }

    public function respondToCounter(Request $request, Conversation $conversation, QuoteCounterOffer $offer): RedirectResponse
    {
        $this->messaging->authorize($request->user(), $conversation);

        // The offer must belong to this thread, or the id simply does not
        // resolve — same posture as the rest of messaging.
        abort_unless((int) $offer->conversation_id === (int) $conversation->getKey(), 404);

        $data = $request->validate(['decision' => ['required', 'string', 'in:accept,decline']]);

        return $this->run(
            $conversation,
            fn () => $this->commerce->respondToCounter($offer, $request->user(), $data['decision']),
            $data['decision'] === 'accept' ? 'Counter-offer accepted. A revised quotation has been issued.' : 'Counter-offer declined.',
        );
    }

    /**
     * Run a commerce action and land back on the thread.
     *
     * A RuntimeException here is a *domain* refusal ("already accepted",
     * "expired") — real, expected outcomes of a race or a stale page, not
     * bugs — so they become a flash message. An HttpException (403/404 from the
     * role gate) is left alone to become the response it is.
     */
    private function run(Conversation $conversation, callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (HttpExceptionInterface|RecordsNotFoundException $e) {
            // Symfony's HttpException extends RuntimeException, so it would be
            // swallowed by the catch below and silently turned into a redirect
            // — turning every 403 from the role gate into an apparent success.
            // It is re-thrown first, deliberately.
            throw $e;
        } catch (RuntimeException $e) {
            return $this->back($conversation)->with('chat_error', $e->getMessage());
        }

        return $this->back($conversation)->with('chat_status', $success);
    }

    private function back(Conversation $conversation): RedirectResponse
    {
        return redirect()->route('account.messages.show', $conversation);
    }
}
