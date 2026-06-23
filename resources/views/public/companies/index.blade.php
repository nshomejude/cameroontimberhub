<x-layouts.app
    title="Verified timber exporters in Cameroon"
    description="Browse verified Cameroonian timber exporters by species, region, and export market. Documents reviewed by Cameroon Timber Hub.">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-6xl px-4 py-14">
            <p class="eyebrow">Verified directory</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl">Timber exporters in Cameroon</h1>
            <p class="mt-3 text-ink-soft dark:text-[#b3ab9b]">
                {{ $companies->total() }} verified {{ Str::plural('exporter', $companies->total()) }} — filter by species, region and export market.
            </p>

            {{-- Desktop: inline filter card. Mobile: a bottom-sheet trigger. --}}
            <div class="mt-8 hidden rounded-2xl border border-sand-200 dark:border-[#2c2a24] bg-white dark:bg-[#1f1d18] p-4 shadow-sm md:block">
                @include('public.partials.directory-filter-fields')
            </div>

            <div class="mt-6 md:hidden">
                <x-bottom-sheet title="Filter exporters">
                    <x-slot:trigger>
                        <button type="button" class="flex w-full items-center justify-between rounded-xl border border-sand-200 dark:border-[#2c2a24] bg-white dark:bg-[#1f1d18] px-4 py-3 text-sm font-medium text-forest-800 dark:text-forest-200 shadow-sm active:scale-[0.99]">
                            <span class="inline-flex items-center gap-2"><x-heroicon-m-funnel class="h-5 w-5 text-timber-500" /> Filter exporters</span>
                            <x-heroicon-m-chevron-up class="h-5 w-5 text-ink-soft dark:text-[#b3ab9b]" />
                        </button>
                    </x-slot:trigger>
                    @include('public.partials.directory-filter-fields')
                </x-bottom-sheet>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-12">
        @if($companies->isNotEmpty())
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($companies as $company)
                    @include('public.partials.company-card', ['company' => $company])
                @endforeach
            </div>
            <div class="mt-10">{{ $companies->links() }}</div>
        @else
            <div class="rounded-2xl border border-dashed border-sand-300 dark:border-[#3a352e] bg-white dark:bg-[#1f1d18] p-14 text-center">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-forest-50 dark:bg-[#1b2c22] text-forest-600 dark:text-forest-400">
                    <x-heroicon-o-magnifying-glass class="h-6 w-6" />
                </span>
                <h2 class="mt-4 font-display text-xl font-semibold text-forest-900 dark:text-sand-100">No verified exporters match these filters yet</h2>
                <p class="mt-2 text-ink-soft dark:text-[#b3ab9b]">Try broadening your search, or tell us what you need and we'll connect you.</p>
                <div class="mt-6 flex justify-center gap-3">
                    <a href="{{ route('directory') }}" class="rounded-full border border-sand-300 dark:border-[#3a352e] px-5 py-2.5 text-sm font-medium text-ink-soft dark:text-[#b3ab9b] transition hover:border-forest-400 hover:text-forest-700">Reset filters</a>
                    <a href="#" class="rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">Request a quote</a>
                </div>
            </div>
        @endif
    </section>
</x-layouts.app>
