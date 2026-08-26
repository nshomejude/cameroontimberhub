<?php

namespace App\Livewire\Messaging;

use App\Enums\ConversationTopic;
use App\Models\Company;
use App\Models\Conversation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "New Conversation" (mockup: the supplier picker with the MESSAGE ABOUT
 * chips). Only publicly-visible companies are listed — the same single gate
 * (Company::scopePubliclyVisible) the directory reads through, so an
 * unverified or incomplete company is not reachable here either.
 *
 * "Recent suppliers" is the buyer's own existing threads, not a guess. The
 * mockup's "Post Requirement" CTA points at the real RFQ wizard.
 */
class NewConversation extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'about', except: 'general')]
    public string $topic = 'general';

    public function setTopic(string $topic): void
    {
        $this->topic = in_array($topic, ConversationTopic::values(), true) ? $topic : 'general';
    }

    public function render(): View
    {
        $user = auth()->user();

        $recentIds = Conversation::query()
            ->forBuyer($user)
            ->orderByDesc('last_message_at')
            ->limit(3)
            ->pluck('company_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $query = Company::query()
            ->publiclyVisible()
            ->with('species:id,common_name');

        if (($term = trim($this->search)) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $query->where(fn ($q) => $q->where('legal_name', 'ilike', $like)->orWhere('trade_name', 'ilike', $like));
        }

        $companies = (clone $query)->whereNotIn('id', $recentIds)->orderBy('legal_name')->limit(25)->get();

        return view('livewire.messaging.new-conversation', [
            'topics' => ConversationTopic::cases(),
            'recent' => $recentIds === [] ? collect() : (clone $query)->whereIn('id', $recentIds)->get(),
            'companies' => $companies,
        ]);
    }
}
