{{--
    Buyer messaging.

    There is no desktop chat mockup, so the desktop breakpoint is inferred: the
    conventional two-pane messenger — the same inbox list pinned to a 22rem
    left column with the thread filling the rest — built from the mobile
    mockup's own components so nothing new is invented visually. Below `lg` it
    collapses to the mockup exactly: the list is one screen, the thread is
    another, reached by tapping a row and left by the back arrow.

    The account layout is noindex, so both screens are.
--}}
<x-layouts.account
    :title="$conversation ? 'Messages — '.$conversation->counterpartyName(auth()->user()) : 'Messages'"
    heading="Messages"
    subheading="Talk to verified suppliers about products, quotes and orders.">

    <div class="mb-4 flex justify-end lg:hidden">
        <a href="{{ route('account.messages.create') }}"
           class="flex items-center gap-2 rounded-xl bg-forest-800 px-4 py-2.5 text-[0.875rem] font-bold text-white transition hover:bg-forest-900">
            <x-heroicon-m-pencil-square class="h-4 w-4" /> New
        </a>
    </div>

    <div class="lg:flex lg:min-h-[calc(100vh-13rem)] lg:gap-5">

        {{-- Conversation list. Hidden on mobile once a thread is open. --}}
        <div @class(['lg:w-[22rem] lg:shrink-0', 'hidden lg:block' => $conversation !== null])>
            <div class="mb-4 hidden justify-end lg:flex">
                <a href="{{ route('account.messages.create') }}"
                   class="flex items-center gap-2 rounded-xl bg-forest-800 px-4 py-2.5 text-[0.875rem] font-bold text-white transition hover:bg-forest-900">
                    <x-heroicon-m-pencil-square class="h-4 w-4" /> New conversation
                </a>
            </div>

            <livewire:messaging.inbox scope="buyer"
                                      thread-route="account.messages.show"
                                      :active-id="$conversation?->getKey()" />
        </div>

        {{-- Thread pane. --}}
        <div class="min-w-0 flex-1">
            @if ($conversation)
                <div class="overflow-hidden rounded-2xl border border-sand-200 bg-white lg:flex lg:h-full lg:flex-col">
                    <livewire:messaging.thread :conversation-id="$conversation->getKey()"
                                               :back-url="route('account.messages')"
                                               :post-action="route('account.messages.store', $conversation)"
                                               :key="'thread-'.$conversation->getKey()" />
                </div>
            @else
                <div class="hidden h-full place-content-center rounded-2xl border border-dashed border-sand-300 bg-white p-10 text-center lg:grid">
                    <div>
                        <x-heroicon-o-chat-bubble-left-right class="mx-auto h-10 w-10 text-sand-400" />
                        <p class="mt-3 font-display text-[1rem] font-bold text-forest-950">Select a conversation</p>
                        <p class="mt-1 text-[0.875rem] text-ink-soft">Or start a new one with a verified supplier.</p>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-layouts.account>
