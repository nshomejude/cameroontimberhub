<div class="space-y-3">
    <label class="relative block">
        <x-heroicon-m-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-soft/60" />
        <input type="search"
               wire:model.live.debounce.400ms="search"
               placeholder="Search exporters…"
               class="w-full rounded-lg border border-sand-300 dark:border-[#3a352e] bg-sand-50/60 dark:bg-[#26241e] py-2.5 pl-10 pr-3 text-sm text-ink dark:text-[#f1ece1] placeholder:text-ink-soft/60 focus:border-forest-500 focus:bg-white dark:focus:bg-[#1f1d18] focus:ring-2 focus:ring-forest-100 focus:outline-none">
    </label>

    <div class="grid gap-3 sm:grid-cols-3">
        <select wire:model.live="region" class="{{ $field }}">
            <option value="">All regions</option>
            @foreach($regions as $r)
                <option value="{{ $r }}">{{ $r }}</option>
            @endforeach
        </select>

        <select wire:model.live="species" class="{{ $field }}">
            <option value="">All species</option>
            @foreach($speciesList as $sp)
                <option value="{{ $sp->slug }}">{{ $sp->common_name }}</option>
            @endforeach
        </select>

        <select wire:model.live="market" class="{{ $field }}">
            <option value="">All export markets</option>
            @foreach($markets as $code)
                <option value="{{ $code }}">{{ $code }}</option>
            @endforeach
        </select>
    </div>

    <div class="flex items-center gap-2">
        <span wire:loading class="inline-flex items-center gap-1.5 text-sm text-ink-soft dark:text-[#b3ab9b]">
            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-dasharray="31.4 31.4" stroke-dashoffset="0"/>
            </svg>
            Filtering…
        </span>
        @if($search || $region || $species || $market)
            <button wire:click="resetFilters" type="button"
                    class="inline-flex items-center gap-1 rounded-full px-4 py-2.5 text-sm font-medium text-ink-soft dark:text-[#b3ab9b] transition hover:text-forest-700">
                <x-heroicon-m-x-mark class="h-4 w-4" /> Clear filters
            </button>
        @endif
    </div>
</div>
