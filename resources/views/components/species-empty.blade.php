<div class="rounded-2xl border border-dashed border-sand-300 bg-white p-10 text-center sm:p-14">
    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-forest-50 text-forest-700">
        <x-heroicon-o-magnifying-glass class="h-6 w-6" />
    </span>
    <h2 class="mt-4 text-[1.125rem] font-bold text-ink">No timber species match these filters</h2>
    <p class="mt-2 text-[1.0625rem] text-ink-soft">Try clearing a filter, or tell us what you need and we'll help you source it.</p>
    <div class="mt-6 flex flex-wrap justify-center gap-3">
        <button type="button" wire:click="resetFilters"
                class="rounded-lg border border-sand-300 px-5 py-2.5 text-[1.0625rem] font-semibold text-ink transition hover:border-forest-600 hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500">
            Clear all filters
        </button>
        <a href="{{ route('rfq.create') }}"
           class="rounded-lg bg-forest-700 px-5 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
            Request a species
        </a>
    </div>
</div>
