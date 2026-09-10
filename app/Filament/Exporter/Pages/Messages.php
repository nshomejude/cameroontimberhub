<?php

namespace App\Filament\Exporter\Pages;

use App\Models\Conversation;
use App\Services\MessagingService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The supplier side of messaging, inside the existing exporter panel at
 * /dashboard/messages — rather than a separate supplier route, so a company
 * member has one place to work and inherits the panel's auth, onboarding gate
 * and chrome.
 *
 * The same two Livewire components the buyer sees are mounted here with
 * `scope="supplier"`, so there is exactly one inbox implementation and one
 * thread implementation. Scope is set by this page, never by the request, and
 * MessagingService still resolves every conversation through the participant
 * scope — so `?conversation=` cannot be used to reach another company's thread.
 */
class Messages extends Page
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.pages.messages');
    }

    public function getTitle(): string
    {
        return __('messages.filament.pages.messages');
    }
    protected string $view = 'filament.exporter.pages.messages';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;



    protected static ?int $navigationSort = 20;

    public ?int $conversation = null;

    /** @var array<string, mixed> */
    protected $queryString = ['conversation'];

    public function mount(): void
    {
        if ($this->conversation === null) {
            return;
        }

        // Resolve through the participant scope; a foreign id simply does not
        // exist for this user and is dropped rather than 404ing the panel.
        $found = Conversation::query()
            ->forSupplier(auth()->user())
            ->whereKey($this->conversation)
            ->first();

        $this->conversation = $found?->getKey();
    }

    /** Real unread total for the navigation badge — never a placeholder. */
    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $count = array_sum(app(MessagingService::class)->unreadCounts(
            $user,
            Conversation::query()->forSupplier($user)->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ));

        return $count > 0 ? (string) $count : null;
    }
}
