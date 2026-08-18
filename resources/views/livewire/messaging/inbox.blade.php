{{--
    Inbox list. Polls every 30s: an inbox only needs to notice that something
    arrived, so it beats a third as often as an open thread.
--}}
<div class="flex min-h-0 flex-col" wire:poll.30s>

    {{-- Filter chips. "Unread" carries a real derived count. --}}
    <div class="flex gap-2 overflow-x-auto pb-3">
        @php
            $chips = [
                ['all', 'Inbox', 'chat-bubble-left-right', $unreadTotal > 0 ? null : null],
                ['unread', 'Unread', 'chat-bubble-oval-left', $unreadTotal ?: null],
                ['starred', 'Starred', 'star', null],
                ['archived', 'Archived', 'archive-box', null],
            ];
        @endphp
        @foreach ($chips as [$key, $label, $icon, $count])
            <button type="button" wire:click="setFilter('{{ $key }}')"
                    @class([
                        'flex shrink-0 items-center gap-2 rounded-full px-4 py-2.5 text-[0.875rem] font-semibold transition',
                        'bg-forest-800 text-white' => $filter === $key,
                        'border border-sand-300 bg-white text-ink hover:bg-sand-50' => $filter !== $key,
                    ])>
                <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-4 w-4" />
                {{ $label }}
                @if ($count)
                    <span class="rounded-full bg-forest-600 px-1.5 text-[0.6875rem] font-bold text-white">{{ $count }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <label for="inbox-search" class="sr-only">Search conversations</label>
    <div class="mb-3 flex items-center rounded-xl border border-sand-300 bg-white">
        <x-heroicon-m-magnifying-glass class="ml-3 h-4 w-4 shrink-0 text-ink-soft" />
        <input id="inbox-search" type="search" wire:model.live.debounce.400ms="search"
               placeholder="Search name or message…"
               class="min-w-0 flex-1 bg-transparent px-3 py-2.5 text-[0.875rem] outline-none placeholder:text-ink-soft">
    </div>

    @if ($conversations->isEmpty())
        <div class="rounded-2xl border border-dashed border-sand-300 bg-white p-8 text-center">
            <p class="font-display text-[1rem] font-bold text-forest-950">No conversations here</p>
            <p class="mt-1 text-[0.875rem] text-ink-soft">
                @if ($filter === 'all')
                    Message a verified supplier to start one.
                @else
                    Nothing matches this filter.
                @endif
            </p>
        </div>
    @else
        <ul class="divide-y divide-sand-200 overflow-hidden rounded-2xl border border-sand-200 bg-white">
            @foreach ($conversations as $conversation)
                @php
                    $count = $unread[$conversation->getKey()] ?? 0;
                    $last = $conversation->latestMessage;
                    $logo = $conversation->counterpartyLogoUrl($user);
                @endphp
                <li @class(['relative transition hover:bg-sand-50', 'bg-forest-50/60' => $activeId === $conversation->getKey()])>
                    {{-- Named param, not positional: the supplier surface is a
                         Filament page that carries the id as ?conversation=. --}}
                    <a href="{{ route($threadRoute, ['conversation' => $conversation->getKey()]) }}"
                       wire:navigate class="flex items-start gap-3 px-4 py-3.5">
                        <span class="h-12 w-12 shrink-0 overflow-hidden rounded-full bg-sand-200">
                            @if ($logo)
                                <img src="{{ $logo }}" alt="" class="h-full w-full object-cover">
                            @else
                                <span class="flex h-full w-full items-center justify-center text-[0.875rem] font-bold text-forest-800">
                                    {{ $conversation->counterpartyInitials($user) }}
                                </span>
                            @endif
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-1.5">
                                <span @class(['truncate text-[0.9375rem] text-forest-950', 'font-bold' => $count > 0, 'font-semibold' => $count === 0])>
                                    {{ $conversation->counterpartyName($user) }}
                                </span>
                                @if ($conversation->counterpartyIsVerified($user))
                                    <x-heroicon-s-check-badge class="h-4 w-4 shrink-0 text-forest-600" />
                                    <span class="sr-only">Verified supplier</span>
                                @endif
                                <span class="ml-auto shrink-0 pl-2 text-[0.75rem] {{ $count > 0 ? 'font-semibold text-forest-700' : 'text-ink-soft' }}">
                                    @if ($conversation->last_message_at?->isToday())
                                        {{ $conversation->last_message_at->format('g:i A') }}
                                    @elseif ($conversation->last_message_at?->isYesterday())
                                        Yesterday
                                    @else
                                        {{ $conversation->last_message_at?->isoFormat('D MMM') }}
                                    @endif
                                </span>
                            </span>

                            <span class="mt-0.5 flex items-start gap-2">
                                <span @class(['line-clamp-2 min-w-0 flex-1 text-[0.875rem] leading-snug', 'text-ink' => $count > 0, 'text-ink-soft' => $count === 0])>
                                    {{ $last?->preview() ?? 'No messages yet' }}
                                </span>
                                @if ($count > 0)
                                    <span class="mt-0.5 flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-forest-600 px-1.5 text-[0.6875rem] font-bold text-white">
                                        {{ $count }}
                                    </span>
                                    <span class="sr-only">{{ $count }} unread</span>
                                @endif
                            </span>
                        </span>
                    </a>

                    {{-- Per-participant flags: starring or archiving never
                         changes what the other side sees. --}}
                    <span class="absolute bottom-2 right-3 flex gap-1">
                        <button type="button" wire:click="toggleStar({{ $conversation->getKey() }})"
                                class="rounded-lg p-1 text-sand-400 transition hover:text-timber-500"
                                aria-label="Star conversation">
                            <x-heroicon-o-star class="h-4 w-4" />
                        </button>
                        <button type="button" wire:click="toggleArchive({{ $conversation->getKey() }})"
                                class="rounded-lg p-1 text-sand-400 transition hover:text-forest-700"
                                aria-label="{{ $filter === 'archived' ? 'Move to inbox' : 'Archive conversation' }}">
                            <x-heroicon-o-archive-box class="h-4 w-4" />
                        </button>
                    </span>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $conversations->links() }}</div>
    @endif
</div>
