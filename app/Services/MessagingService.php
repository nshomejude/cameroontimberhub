<?php

namespace App\Services;

use App\Enums\ConversationStatus;
use App\Enums\ConversationTopic;
use App\Enums\MessageType;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single decision point for messaging, in the same spirit as
 * BuyerRfqAccess: controllers and Livewire components never re-implement the
 * participant check, never derive unread counts themselves, and never write a
 * message row directly.
 *
 * Access model
 * ------------
 * A conversation has exactly two sides. The buyer side is `conversations.user_id`.
 * The supplier side is *company* membership — any user on the `company_user`
 * pivot for `conversations.company_id` — which is the same rule the exporter
 * panel already enforces through Company::scopeDashboardOwned(). Everyone else
 * gets a 404, not a 403: a 403 on a thread you are not part of would confirm
 * that the id exists, which is exactly the enumeration signal we do not want to
 * emit.
 */
class MessagingService
{
    /** Per-page size for the inbox. */
    public const PER_PAGE = 20;

    /* ------------------------------------------------------------- access */

    /** True when this user may read and post in this thread. */
    public function allows(?User $user, Conversation $conversation): bool
    {
        return $conversation->includes($user);
    }

    /**
     * 404 rather than 403 — see the class docblock. Deliberately raised before
     * any conversation attribute is read, so nothing leaks into the response.
     */
    public function authorize(?User $user, Conversation $conversation): void
    {
        abort_unless($this->allows($user, $conversation), 404);
    }

    /**
     * Resolve a conversation by id *for this user*, or 404. The only supported
     * way to turn a request-supplied id into a Conversation: the scope is
     * applied in the query, so a non-participant's id simply does not resolve.
     */
    public function find(User $user, int|string $id): Conversation
    {
        return Conversation::query()
            ->forParticipant($user)
            ->whereKey($id)
            ->firstOrFail();
    }

    /**
     * This user's participant row, provisioned on first access.
     *
     * Buyer rows are created with the conversation. Supplier rows cannot be —
     * a company can have many staff and we do not know which of them will open
     * the thread — so they are minted lazily here, *after* authorisation.
     */
    public function participantFor(User $user, Conversation $conversation): ConversationParticipant
    {
        $this->authorize($user, $conversation);

        $isBuyer = (int) $conversation->user_id === (int) $user->getKey();

        return ConversationParticipant::firstOrCreate(
            ['conversation_id' => $conversation->getKey(), 'user_id' => $user->getKey()],
            [
                'role' => $isBuyer ? ConversationParticipant::ROLE_BUYER : ConversationParticipant::ROLE_SUPPLIER,
                'company_id' => $isBuyer ? null : $conversation->company_id,
            ],
        );
    }

    /* -------------------------------------------------------- conversation */

    /**
     * Find-or-create the thread between this buyer and this company.
     *
     * One live thread per (buyer, company, order) so "Message Supplier" from a
     * profile, a product page and the inbox all land in the same place instead
     * of littering the inbox with empty duplicates. A product or RFQ mentioned
     * on the way in is recorded as context on the existing thread.
     */
    public function start(
        User $buyer,
        Company $company,
        ConversationTopic $topic = ConversationTopic::General,
        ?Product $product = null,
        ?Order $order = null,
        ?string $subject = null,
    ): Conversation {
        $conversation = Conversation::query()
            ->where('user_id', $buyer->getKey())
            ->where('company_id', $company->getKey())
            ->where('order_id', $order?->getKey())
            ->where('status', '!=', ConversationStatus::Closed->value)
            ->orderByDesc('id')
            ->first();

        if ($conversation === null) {
            $conversation = Conversation::create([
                'user_id' => $buyer->getKey(),
                'company_id' => $company->getKey(),
                'topic' => $topic->value,
                'status' => ConversationStatus::Open->value,
                'subject' => $subject ? Str::limit(trim($subject), 170, '') : null,
                'product_id' => $product?->getKey(),
                'order_id' => $order?->getKey(),
                'rfq_id' => $order?->rfq_id,
                'quote_id' => $order?->quote_id,
                'last_message_at' => now(),
            ]);

            $participant = ConversationParticipant::create([
                'conversation_id' => $conversation->getKey(),
                'user_id' => $buyer->getKey(),
                'role' => ConversationParticipant::ROLE_BUYER,
            ]);

            $this->postSystem($conversation, $this->openingNote($company, $topic, $product, $order));

            if ($product) {
                $this->postProductReference($conversation, $buyer, $product);
            }

            if ($order) {
                $this->postOrderReference($conversation, $buyer, $order);
            }

            // Advanced AFTER the opening cards, so the buyer who just opened the
            // thread does not immediately see their own context cards as unread.
            // They stay unread for the supplier, which is the point.
            $participant->forceFill([
                'last_read_message_id' => $conversation->messages()->max('id'),
                'last_read_at' => now(),
            ])->save();

            return $conversation->refresh();
        }

        // Existing thread: only ever *add* context that was missing.
        $fill = array_filter([
            'product_id' => $conversation->product_id === null ? $product?->getKey() : null,
        ], fn ($v) => $v !== null);

        if ($fill !== []) {
            $conversation->forceFill($fill)->save();
        }

        return $conversation;
    }

    private function openingNote(Company $company, ConversationTopic $topic, ?Product $product, ?Order $order): string
    {
        return match (true) {
            $order !== null => 'Conversation started about order '.$order->reference_code.'.',
            $product !== null => 'Conversation started about '.$product->name.'.',
            default => 'Conversation started with '.$company->name.' — '.$topic->label().'.',
        };
    }

    /* ------------------------------------------------------------ posting */

    /**
     * Post prose. The body is stored as-is and escaped by Blade at render
     * time; no HTML is ever parsed out of it, so a `<script>` a buyer types is
     * text forever, on both sides of the conversation.
     */
    public function post(Conversation $conversation, User $sender, string $body, ?Message $replyTo = null): Message
    {
        $this->authorize($sender, $conversation);

        $body = trim(preg_replace('/\R{3,}/u', "\n\n", $body) ?? '');

        abort_if($body === '', 422);

        $participant = $this->participantFor($sender, $conversation);

        $message = $this->write($conversation, [
            'sender_user_id' => $sender->getKey(),
            'sender_company_id' => $participant->isSupplier() ? $conversation->company_id : null,
            'type' => MessageType::Text->value,
            'body' => Str::limit($body, 4000, ''),
            'reply_to_message_id' => $replyTo?->conversation_id === $conversation->getKey() ? $replyTo->getKey() : null,
        ]);

        // Sending is also reading: your own message can never be unread.
        $participant->forceFill([
            'last_read_message_id' => $message->getKey(),
            'last_read_at' => $message->created_at,
        ])->save();

        return $message;
    }

    /** Platform-authored note. No sender, never deletable. */
    public function postSystem(Conversation $conversation, string $body): Message
    {
        return $this->write($conversation, [
            'type' => MessageType::System->value,
            'body' => $body,
        ]);
    }

    /**
     * The order reference card.
     *
     * The payload snapshots what must not move — the reference, the currency
     * and the total as agreed at award time, and the item line. The status,
     * the milestone stamps and the payment state are NOT snapshotted: they are
     * read live off the related Order every time the card renders.
     */
    public function postOrderReference(Conversation $conversation, ?User $sender, Order $order): Message
    {
        $order->loadMissing('items');

        $item = $order->items->first();

        return $this->write($conversation, [
            'sender_user_id' => $sender?->getKey(),
            'type' => MessageType::OrderReference->value,
            'body' => null,
            'payload' => [
                'reference_code' => $order->reference_code,
                'currency' => $order->currency->value,
                'total_amount' => (string) $order->total_amount,
                'supplier_name' => $order->supplier_name,
                'ordered_at' => $order->awarded_at?->toIso8601String(),
                'item_description' => $item?->description,
                'item_quantity' => $item ? rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.') : null,
                'item_unit' => $item?->unit,
            ],
            'related_type' => $order->getMorphClass(),
            'related_id' => $order->getKey(),
        ]);
    }

    /** The milestone trail card. Entirely live — it snapshots nothing. */
    public function postOrderStatus(Conversation $conversation, Order $order): Message
    {
        return $this->write($conversation, [
            'type' => MessageType::OrderStatus->value,
            'payload' => ['reference_code' => $order->reference_code],
            'related_type' => $order->getMorphClass(),
            'related_id' => $order->getKey(),
        ]);
    }

    /** The product context card at the head of a product-started thread. */
    public function postProductReference(Conversation $conversation, ?User $sender, Product $product): Message
    {
        return $this->write($conversation, [
            'sender_user_id' => $sender?->getKey(),
            'type' => MessageType::ProductReference->value,
            'payload' => [
                'name' => $product->name,
                'slug' => $product->slug,
                'image_url' => $product->primaryImageUrl(),
            ],
            'related_type' => $product->getMorphClass(),
            'related_id' => $product->getKey(),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function write(Conversation $conversation, array $attributes): Message
    {
        return DB::transaction(function () use ($conversation, $attributes) {
            $message = $conversation->messages()->create($attributes);

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            return $message;
        });
    }

    /** Soft-delete one's own prose. The row stays for audit; the bubble goes. */
    public function delete(Message $message, User $user): void
    {
        abort_unless($message->isDeletableBy($user), 403);

        $message->delete();
    }

    /* ------------------------------------------------------------- unread */

    public function markRead(Conversation $conversation, User $user): void
    {
        $this->participantFor($user, $conversation)
            ->forceFill([
                'last_read_message_id' => $conversation->messages()->max('id'),
                'last_read_at' => now(),
            ])
            ->save();
    }

    /**
     * Real unread counts for a page of conversations, in ONE grouped query.
     *
     * A message is unread for $user when it was not written by them and its id
     * is above their read cursor. Nothing is stored, so nothing drifts.
     *
     * @param  list<int>  $conversationIds
     * @return array<int, int> conversation id => count
     */
    public function unreadCounts(User $user, array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $rows = DB::table('messages as m')
            ->join('conversation_participants as p', function ($join) use ($user) {
                $join->on('p.conversation_id', '=', 'm.conversation_id')
                    ->where('p.user_id', '=', $user->getKey());
            })
            ->whereIn('m.conversation_id', $conversationIds)
            ->whereNull('m.deleted_at')
            ->where(fn ($q) => $q->whereNull('m.sender_user_id')->orWhereColumn('m.sender_user_id', '!=', 'p.user_id'))
            ->where(fn ($q) => $q->whereNull('p.last_read_message_id')->orWhereColumn('m.id', '>', 'p.last_read_message_id'))
            ->groupBy('m.conversation_id')
            ->select('m.conversation_id', DB::raw('count(*) as unread_total'))
            ->get();

        return $rows
            ->mapWithKeys(fn ($row) => [(int) $row->conversation_id => (int) $row->unread_total])
            ->all();
    }

    /** Total unread across every thread this user participates in. */
    public function totalUnread(User $user): int
    {
        $ids = Conversation::query()->forParticipant($user)->pluck('id')->all();

        return array_sum($this->unreadCounts($user, array_map('intval', $ids)));
    }

    /**
     * Has the counterparty read up to this message? Real read state, derived
     * from their read cursor — not a delivery receipt we cannot observe.
     */
    public function isReadByCounterparty(Message $message, User $sender): bool
    {
        if (! $message->isFrom($sender)) {
            return false;
        }

        return $message->conversation
            ->participants
            ->first(fn (ConversationParticipant $p) => (int) $p->user_id !== (int) $sender->getKey()
                && $p->last_read_message_id !== null
                && (int) $p->last_read_message_id >= (int) $message->getKey()) !== null;
    }

    /* --------------------------------------------------------------- read */

    /**
     * The inbox page. Every relation the list renders is eager-loaded here, so
     * the view issues no per-row query regardless of how many threads there are.
     *
     * @param  'all'|'unread'|'starred'|'archived'  $filter
     */
    public function inbox(User $user, string $scope = 'participant', string $filter = 'all', string $search = ''): LengthAwarePaginator
    {
        $query = Conversation::query()
            ->when($scope === 'buyer', fn (Builder $q) => $q->forBuyer($user))
            ->when($scope === 'supplier', fn (Builder $q) => $q->forSupplier($user))
            ->when($scope === 'participant', fn (Builder $q) => $q->forParticipant($user))
            ->with([
                'company:id,slug,legal_name,trade_name,logo_path,verified_at',
                'user:id,name',
                'latestMessage',
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        $search = trim($search);

        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

            $query->where(fn (Builder $q) => $q
                ->where('subject', 'ilike', $like)
                ->orWhereHas('company', fn (Builder $c) => $c->where('legal_name', 'ilike', $like)->orWhere('trade_name', 'ilike', $like))
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'ilike', $like))
                ->orWhereHas('messages', fn (Builder $m) => $m->where('body', 'ilike', $like))
            );
        }

        // Archived is a per-participant flag, so "Inbox" means "not archived
        // BY ME" — a supplier archiving a thread never hides it from the buyer.
        $archived = fn (Builder $p) => $p->where('user_id', $user->getKey())->where('is_archived', true);

        $filter === 'archived'
            ? $query->whereHas('participants', $archived)
            : $query->whereDoesntHave('participants', $archived);

        if ($filter === 'starred') {
            $query->whereHas('participants', fn (Builder $p) => $p->where('user_id', $user->getKey())->where('is_starred', true));
        }

        if ($filter === 'unread') {
            // Restrict to threads that genuinely have unread messages, before
            // paginating, so the page count is honest rather than a filtered
            // slice of a larger page.
            $query->whereKey(array_keys($this->unreadCounts(
                $user,
                Conversation::query()->forParticipant($user)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            )));
        }

        return $query->paginate(self::PER_PAGE)->withQueryString();
    }

    /**
     * The thread, oldest first, with every relation the message partials touch
     * eager-loaded — including the polymorphic `related` records, so a thread
     * with fifty order cards still costs a bounded number of queries.
     */
    public function messages(Conversation $conversation, int $limit = 200): EloquentCollection
    {
        $conversation->loadMissing('participants');

        $messages = $conversation->messages()
            ->with(['sender:id,name', 'senderCompany:id,legal_name,trade_name,logo_path', 'replyTo', 'related'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        // Hand every message the conversation it already belongs to, so the
        // read-state check below never lazy-loads it back per bubble.
        $messages->each(fn (Message $message) => $message->setRelation('conversation', $conversation));

        return $messages;
    }
}
