{{--
    A prose bubble.

    $message->body is echoed through Blade's default escaping. It is never
    rendered with {!! !!} and no HTML is whitelisted anywhere in messaging, so
    markup a sender types stays literal text on both sides of the thread.
--}}
@php
    $mine = $message->isFrom($user);
    $read = $mine && $messaging->isReadByCounterparty($message, $user);
@endphp

<div class="flex items-end gap-2 {{ $mine ? 'justify-end' : 'justify-start' }}"
     id="m{{ $message->getKey() }}">

    @unless ($mine)
        <span class="h-8 w-8 shrink-0 overflow-hidden rounded-full bg-sand-200">
            @if ($avatar = $conversation->counterpartyLogoUrl($user))
                <img src="{{ $avatar }}" alt="" class="h-full w-full object-cover">
            @else
                <span class="flex h-full w-full items-center justify-center text-[0.8125rem] font-bold text-forest-800">
                    {{ $conversation->counterpartyInitials($user) }}
                </span>
            @endif
        </span>
    @endunless

    <div class="max-w-[78%] min-w-0">
        @if ($message->replyTo)
            <div class="mb-1 truncate rounded-t-xl border-l-2 border-forest-500 bg-sand-200/70 px-3 py-1.5 text-[0.9375rem] text-ink-soft">
                {{ $message->replyTo->preview(70) }}
            </div>
        @endif

        <div @class([
                'rounded-2xl px-3.5 py-2.5 text-[1.125rem] leading-relaxed shadow-sm',
                'bg-forest-100 text-ink rounded-br-md' => $mine,
                'bg-white text-ink ring-1 ring-sand-200 rounded-bl-md' => ! $mine,
            ])>
            <p class="whitespace-pre-line break-words">{{ $message->body }}</p>

            <p class="mt-1 flex items-center justify-end gap-1 text-[0.875rem] text-ink-soft">
                <span>{{ $message->created_at->format('g:i A') }}</span>
                @if ($mine)
                    {{-- Real read state: derived from the counterparty's
                         last_read_at, not an invented delivery receipt. --}}
                    <x-heroicon-m-check-circle @class(['h-3.5 w-3.5', 'text-forest-600' => $read, 'text-sand-400' => ! $read])
                        aria-hidden="true" />
                    <span class="sr-only">{{ $read ? 'Read' : 'Sent' }}</span>
                @endif
            </p>
        </div>

        {{-- Message actions. Only what can be backed honestly: reply, copy
             (client-side), and delete-your-own. --}}
        <div class="mt-1 flex gap-3 text-[0.875rem] font-semibold text-ink-soft {{ $mine ? 'justify-end' : 'justify-start' }}">
            <button type="button" wire:click="reply({{ $message->getKey() }})" class="transition hover:text-forest-700">Reply</button>
            <button type="button"
                    x-data
                    @click="navigator.clipboard?.writeText($refs.body{{ $message->getKey() }}.textContent.trim())"
                    class="transition hover:text-forest-700">Copy</button>
            <span x-ref="body{{ $message->getKey() }}" class="hidden">{{ $message->body }}</span>
            @if ($message->isDeletableBy($user))
                <button type="button"
                        wire:click="deleteMessage({{ $message->getKey() }})"
                        wire:confirm="Delete this message?"
                        class="transition hover:text-red-700">Delete</button>
            @endif
        </div>
    </div>
</div>
