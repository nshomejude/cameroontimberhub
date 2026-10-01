<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConversationStatus;
use App\Enums\ConversationTopic;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ConversationResource;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use App\Services\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    /**
     * Start (or reuse) a conversation with a supplier company — the API
     * counterpart of `Public\MessageController::start()` (POST
     * /account/messages/start), through the exact same
     * `MessagingService::start()` call, followed by the buyer's first message
     * through `MessagingService::post()` (the same write `postMessage()` uses).
     *
     * Buyer-only (`api.buyer` on the route): `MessagingService::start()` always
     * seats the caller as the conversation's BUYER, so a supplier calling it
     * would open a thread with the wrong sides. A supplier reaches a buyer
     * through an existing thread (or the exporter panel's "Share in chat"
     * quote action), never through this endpoint.
     *
     * Mirrors the web's verified-email gate exactly: an unverified account may
     * re-open an existing open thread with the same company/order, but may not
     * open a NEW one — 403 `email_unverified` instead of the web's redirect to
     * the verification notice.
     *
     * `company` accepts the numeric id or the slug. `order` is the order's
     * reference code and, like the web, only attaches when this buyer owns it
     * (and it was placed with this same company). `product_id`, when given,
     * must be one of this company's products. 201 for a new thread, 200 when
     * an open one was reused.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'company' => ['required'],
            'product_id' => ['nullable', 'integer'],
            'order' => ['nullable', 'string', 'max:64'],
            'topic' => ['nullable', 'string', Rule::in(ConversationTopic::values())],
            'subject' => ['nullable', 'string', 'max:170'],
            'body' => ['required', 'string', 'min:1', 'max:4000'],
        ]);

        $companyKey = (string) $data['company'];
        $company = Company::query()
            ->when(
                ctype_digit($companyKey),
                fn ($q) => $q->whereKey((int) $companyKey),
                fn ($q) => $q->where('slug', $companyKey),
            )
            ->first();

        if ($company === null) {
            throw ValidationException::withMessages(['company' => [__('validation.exists', ['attribute' => 'company'])]]);
        }

        // Only a company buyers can actually see (verified, complete, badged —
        // Company::scopePubliclyVisible) can be messaged; a suspended,
        // archived, pending or otherwise hidden one is refused, even when an
        // older thread with it exists.
        if (! $company->canReceiveMessages()) {
            throw new ApiException(422, 'company_unavailable', __('messages.account_center.company_unavailable'));
        }

        $product = null;
        if (isset($data['product_id'])) {
            $product = Product::whereKey($data['product_id'])->where('company_id', $company->getKey())->first();

            if ($product === null) {
                throw ValidationException::withMessages(['product_id' => [__('validation.exists', ['attribute' => 'product'])]]);
            }
        }

        $order = null;
        if (filled($data['order'] ?? null)) {
            $order = Order::where('user_id', $user->getKey())
                ->where('company_id', $company->getKey())
                ->where('reference_code', $data['order'])
                ->first();

            if ($order === null) {
                throw ValidationException::withMessages(['order' => [__('validation.exists', ['attribute' => 'order'])]]);
            }
        }

        // Same lookup MessagingService::start() reuses a thread by.
        $existing = Conversation::query()
            ->where('user_id', $user->getKey())
            ->where('company_id', $company->getKey())
            ->where('order_id', $order?->getKey())
            ->where('status', '!=', ConversationStatus::Closed->value)
            ->exists();

        if (! $existing && ! $user->hasVerifiedEmail()) {
            throw new ApiException(
                403,
                'email_unverified',
                __('Please verify your email address before messaging suppliers. Check your inbox for the link, or resend it below.'),
            );
        }

        $conversation = $this->messaging->start(
            buyer: $user,
            company: $company,
            topic: ConversationTopic::tryFrom($data['topic'] ?? '')
                ?? ($order ? ConversationTopic::Order : ($product ? ConversationTopic::Product : ConversationTopic::General)),
            product: $product,
            order: $order,
            subject: $data['subject'] ?? null,
        );

        $this->messaging->post($conversation, $user, $data['body']);

        $conversation = $this->messaging->find($user, (int) $conversation->getKey());
        $conversation->load('company:id,slug,legal_name,trade_name,logo_path,verified_at', 'user:id,name', 'latestMessage');

        return response()->json(['data' => new ConversationResource($conversation)], $existing ? 200 : 201);
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
