@props(['title' => null])

<div x-data="{
        open: false,
        dragY: 0,
        startY: 0,
        dragging: false,
        start(e) { this.startY = e.touches[0].clientY; this.dragging = true; },
        move(e) { if (! this.dragging) return; const dy = e.touches[0].clientY - this.startY; if (dy > 0) this.dragY = dy; },
        end() { this.dragging = false; if (this.dragY > 120) this.open = false; this.dragY = 0; },
    }"
    @keydown.escape.window="open = false">

    <div @click="open = true">{{ $trigger }}</div>

    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-[60]">
            <div x-show="open" x-transition.opacity @click="open = false" class="absolute inset-0 bg-black/40"></div>

            <div x-show="open"
                 class="bottom-sheet-panel absolute inset-x-0 bottom-0 max-h-[88vh] overflow-y-auto rounded-t-3xl bg-white dark:bg-[#1f1d18] shadow-2xl"
                 style="padding-bottom: env(safe-area-inset-bottom)"
                 :style="dragY ? `transform: translateY(${dragY}px)` : ''">
                <div class="sticky top-0 flex justify-center bg-white dark:bg-[#1f1d18] pt-3 pb-1"
                     @touchstart.passive="start($event)" @touchmove.passive="move($event)" @touchend="end()">
                    <span class="h-1.5 w-10 rounded-full bg-sand-300"></span>
                </div>
                @if ($title)
                    <h2 class="px-5 font-display text-lg font-semibold text-forest-900 dark:text-sand-100">{{ $title }}</h2>
                @endif
                <div class="px-5 pb-6 pt-3">{{ $slot }}</div>
            </div>
        </div>
    </template>
</div>
