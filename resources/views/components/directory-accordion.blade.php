@props([
    'title',
    'id',
    'open' => false,
])

{{-- Collapsed filter section, per the directory mockup's Location /
     Certification / Experience rows. Keyboard-operable: the trigger is a real
     button and the panel is wired with aria-controls / aria-expanded. --}}
<div x-data="{ open: @js($open) }" class="py-1">
    <h3>
        <button type="button" @click="open = !open"
                :aria-expanded="open ? 'true' : 'false'"
                aria-controls="{{ $id }}-panel"
                class="flex w-full items-center justify-between rounded py-3 text-left text-[1.0625rem] font-bold text-ink transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">
            {{ $title }}
            <x-heroicon-m-chevron-down class="h-4 w-4 text-ink-soft transition" ::class="open && 'rotate-180'" />
        </button>
    </h3>
    <div id="{{ $id }}-panel" x-show="open" x-cloak class="pb-2">
        {{ $slot }}
    </div>
</div>
