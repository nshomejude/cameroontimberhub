<?php

namespace App\Livewire\Messaging;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\MessagingService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

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
        ]);
    }
}
