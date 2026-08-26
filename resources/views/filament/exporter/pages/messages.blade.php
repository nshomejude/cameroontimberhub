{{-- Supplier inbox + thread, reusing the buyer components with scope="supplier". --}}
<x-filament-panels::page>
    <div class="lg:flex lg:gap-5">
        <div class="lg:w-[22rem] lg:shrink-0">
            <livewire:messaging.inbox scope="supplier"
                                      thread-route="filament.exporter.pages.messages"
                                      :active-id="$this->conversation" />
        </div>

        <div class="min-w-0 flex-1">
            @if ($this->conversation)
                <div class="overflow-hidden rounded-2xl border border-sand-200 bg-white">
                    <livewire:messaging.thread :conversation-id="$this->conversation"
                                               :key="'sup-thread-'.$this->conversation" />
                </div>
            @else
                <div class="hidden place-content-center rounded-2xl border border-dashed border-sand-300 bg-white p-10 text-center lg:grid">
                    <p class="font-display text-base font-bold text-forest-950">Select a conversation</p>
                    <p class="mt-1 text-sm text-ink-soft">Buyer messages about your products, quotes and orders appear here.</p>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
