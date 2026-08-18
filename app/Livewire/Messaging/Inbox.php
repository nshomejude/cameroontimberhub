<?php

namespace App\Livewire\Messaging;

use App\Services\MessagingService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The conversation list (mockup: "Mobile messenger inbox").
 *
 * Serves both sides. `$scope` decides which conversations the signed-in user
 * is entitled to see — 'buyer' under /account, 'supplier' inside the exporter
 * panel — and is set by the surface, never by the request.
 *
 * Everything the mockup shows is real: the counterparty is the company (or the
 * buyer account) on the row, the preview is the last message's body, the
 * timestamp is `last_message_at`, and the unread pill is a derived count. The
 * mockup's green "online" dots are NOT rendered: there is no presence tracking
 * on this platform and a permanently-green dot would be a lie.
 */
class Inbox extends Component
{
    use WithPagination;

    /** 'buyer' | 'supplier' — set by the host surface. */
    public string $scope = 'buyer';

    /** Route name used to build a thread link on this surface. */
    public string $threadRoute = 'account.messages.show';

    public ?int $activeId = null;

    #[Url(as: 'f', except: 'all')]
    public string $filter = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'unread', 'starred', 'archived'], true) ? $filter : 'all';
        $this->resetPage();
    }

    /** Star / unstar is per-participant, so it never touches the other side. */
    public function toggleStar(int $conversationId): void
    {
        $messaging = app(MessagingService::class);
        $conversation = $messaging->find(auth()->user(), $conversationId);
        $participant = $messaging->participantFor(auth()->user(), $conversation);

        $participant->forceFill(['is_starred' => ! $participant->is_starred])->save();
    }

    public function toggleArchive(int $conversationId): void
    {
        $messaging = app(MessagingService::class);
        $conversation = $messaging->find(auth()->user(), $conversationId);
        $participant = $messaging->participantFor(auth()->user(), $conversation);

        $participant->forceFill(['is_archived' => ! $participant->is_archived])->save();
    }

    public function render(): View
    {
        $user = auth()->user();
        $messaging = app(MessagingService::class);

        $conversations = $messaging->inbox($user, $this->scope, $this->filter, $this->search);

        // One grouped query for the whole page — no per-row unread lookup.
        $unread = $messaging->unreadCounts($user, $conversations->pluck('id')->map(fn ($id) => (int) $id)->all());

        return view('livewire.messaging.inbox', [
            'user' => $user,
            'conversations' => $conversations,
            'unread' => $unread,
            'unreadTotal' => $messaging->totalUnread($user),
        ]);
    }
}
