@props(['product'])

@php
    $species = $product->species;
    // Only rendered when a PUBLIC contact really publishes WhatsApp or a phone.
    $chat = $product->company?->chatLink();

    $quoteUrl = route('rfq.create').($species ? '?species='.$species->slug : '');
@endphp

{{-- Sticky action bar. It sits directly above the app's mobile tab bar
     (4.5rem) and respects the device safe area, so it never double-stacks
     with it or covers page content — the page reserves matching bottom
     padding. --}}
<div class="fixed inset-x-0 z-30 border-t border-sand-200 bg-white px-4 py-3 lg:hidden"
     style="bottom: calc(4.5rem + env(safe-area-inset-bottom))">
    <div class="flex gap-3">
        <a href="{{ $quoteUrl }}"
           @class([
               'flex items-center justify-center gap-2 rounded-lg border border-forest-700 px-4 py-3 text-[0.9375rem] font-semibold text-forest-800 transition hover:bg-forest-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2',
               'flex-1' => $chat !== null,
               'w-full' => $chat === null,
           ])>
            <x-heroicon-o-paper-airplane class="h-5 w-5" aria-hidden="true" />
            Request Quote
        </a>

        @if ($chat)
            <a href="{{ $chat['url'] }}"
               @if ($chat['channel'] === 'whatsapp') target="_blank" rel="noopener noreferrer" @endif
               class="flex flex-1 items-center justify-center gap-2 rounded-lg bg-forest-700 px-4 py-3 text-[0.9375rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                <x-dynamic-component :component="$chat['channel'] === 'whatsapp' ? 'heroicon-o-chat-bubble-oval-left' : 'heroicon-o-phone'" class="h-5 w-5" aria-hidden="true" />
                {{ $chat['label'] }}
            </a>
        @endif
    </div>
</div>
