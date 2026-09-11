<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ConversationResource;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Conversation;
use App\Services\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;

/**
 * Plain buyer <-> supplier messaging over token auth — the API counterpart of
 * `App\Livewire\Messaging\Inbox` / `Thread` for the buyer mobile app.
 *
 * Deliberately scoped to plain messages only for this pass (see the task that
 * introduced this controller and docs/api/MOBILE_APP_INTEGRATION.md): reading
 * an inbox, reading a thread, posting plain prose, and marking a thread read.
 * `MessagingService::postQuotation()`/`postCounterOffer()`/
 * `postContractAcceptance()`/RFQ-from-chat (`ChatCommerceService`) are
 * commerce actions with side effects and are NOT exposed here — a message of
 * one of those kinds still appears in `messages()`'s output (via
 * `MessageResource`'s `kind`/`payload`), read-only, exactly as the web thread
 * shows it, so the client can render "Quote QTE-2026-X issued" even though it
 * cannot issue one through this API.
 *
 * Authorisation goes entirely through `MessagingService::find()`/`authorize()`
 * — the same 404-not-403 boundary the web UI relies on (see that service's
 * class docblock): a conversation this buyer does not participate in simply
 * does not resolve, via `Conversation::scopeForParticipant()` inside `find()`,
 * so `firstOrFail()` throws `ModelNotFoundException` and `ErrorEnvelope` turns
 * that into a plain 404 — never a 403 that would confirm the id exists.
 */
class ConversationController extends Controller
{
    /** Matches `MessagingService::messages()`'s own default/cap. */
    private const MAX_MESSAGES = 200;

    public function __construct(private readonly MessagingService $messaging) {}

    /**
     * The inbox. Mirrors `OrderController::index()`'s pagination shape: a
     * plain Laravel paginator wrapped in `::collection()`, so the response
     * carries the framework's standard `data`/`links`/`meta` envelope like
     * every other v1 list endpoint.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $conversations = $this->messaging->inbox(
            $request->user(),
            'participant',
            'all',
            (string) $request->string('q', ''),
        );

        return ConversationResource::collection($this->withUnreadCounts($conversations));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $conversation = $this->messaging->find($request->user(), $id);
        $conversation->load('company:id,slug,legal_name,trade_name,logo_path,verified_at', 'user:id,name', 'latestMessage');

        return response()->json(['data' => new ConversationResource($conversation)]);
    }

    /**
     * The thread, oldest-first — the same order `MessagingService::messages()`
     * returns and `App\Livewire\Messaging\Thread` renders directly (it never
     * reverses the collection), so a client rendering top-to-bottom matches
     * the web reading order without any client-side re-sorting.
     */
    public function messages(Request $request, int $id): AnonymousResourceCollection
    {
        $conversation = $this->messaging->find($request->user(), $id);

        $limit = (int) $request->integer('limit', self::MAX_MESSAGES);
        $limit = max(1, min($limit, self::MAX_MESSAGES));

        $messages = $this->messaging->messages($conversation, $limit);

        return MessageResource::collection($messages);
    }

    /** Body validation mirrors `App\Livewire\Messaging\Thread`'s own rule set exactly. */
    public function postMessage(Request $request, int $id): JsonResponse
    {
        $conversation = $this->messaging->find($request->user(), $id);

        $validated = Validator::make($request->all(), [
            'body' => ['required', 'string', 'min:1', 'max:4000'],
        ])->validate();

        $message = $this->messaging->post($conversation, $request->user(), $validated['body']);

        return response()->json(['data' => new MessageResource($message)], 201);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $conversation = $this->messaging->find($request->user(), $id);

        $this->messaging->markRead($conversation, $request->user());

        return response()->json(['data' => ['unread_count' => 0]]);
    }

    /**
     * Stamp every row of the page with its real unread count from ONE grouped
     * query, so `ConversationResource` never issues a per-row query — the
     * same batching discipline `MessagingService::inbox()`/`unreadCounts()`
     * already apply.
     */
    private function withUnreadCounts(LengthAwarePaginator $conversations): LengthAwarePaginator
    {
        $ids = collect($conversations->items())->map(fn (Conversation $c) => (int) $c->id)->all();
        $counts = $this->messaging->unreadCounts(request()->user(), $ids);

        $conversations->getCollection()->each(
            fn (Conversation $c) => $c->setAttribute('unread_count', $counts[$c->id] ?? 0)
        );

        return $conversations;
    }
}
