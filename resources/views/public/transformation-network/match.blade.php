<x-layouts.app
    :title="__('messages.transformation.match_meta_title')"
    :description="__('messages.transformation.match_meta_description')">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-3xl px-4 py-12">
            <p class="eyebrow">{{ __('messages.common.domestic_market_eyebrow') }}</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl">{{ __('messages.transformation.match_heading') }}</h1>
            <p class="mt-3 max-w-xl text-lg text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.transformation.match_intro') }}</p>
        </div>
    </section>

    <div class="mx-auto max-w-3xl px-4 py-10">
        <form method="get" action="{{ route('transformation-network.match') }}"
              class="flex flex-wrap items-end gap-3 rounded-2xl border border-sand-200 dark:border-[#2c2a24] bg-white dark:bg-[#1f1d18] p-5">
            <div class="min-w-[10rem] flex-1">
                <label class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.home.species_label') }}</label>
                <select name="species" class="w-full rounded-lg border border-sand-300 dark:border-[#3a352e] bg-white dark:bg-[#1f1d18] px-3 py-2.5 text-[0.9375rem] text-ink dark:text-[#f1ece1] focus:border-forest-500 focus:ring-2 focus:ring-forest-100 focus:outline-none">
                    <option value="">{{ __('messages.transformation.select_species') }}</option>
                    @foreach ($speciesOptions as $option)
                        <option value="{{ $option->slug }}" @selected($species === $option->slug)>{{ $option->common_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[8rem]">
                <label class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.transformation.quantity_m3') }}</label>
                <input type="number" step="0.01" name="quantity" value="{{ $quantity }}" placeholder="e.g. 100"
                       class="w-full rounded-lg border border-sand-300 dark:border-[#3a352e] bg-white dark:bg-[#1f1d18] px-3 py-2.5 text-[0.9375rem] text-ink dark:text-[#f1ece1] focus:border-forest-500 focus:ring-2 focus:ring-forest-100 focus:outline-none">
            </div>
            <div class="min-w-[8rem]">
                <label class="mb-1 block text-sm font-medium text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.transformation.period') }}</label>
                <select name="period" class="w-full rounded-lg border border-sand-300 dark:border-[#3a352e] bg-white dark:bg-[#1f1d18] px-3 py-2.5 text-[0.9375rem] text-ink dark:text-[#f1ece1] focus:border-forest-500 focus:ring-2 focus:ring-forest-100 focus:outline-none">
                    @foreach (['day', 'week', 'month', 'quarter', 'year'] as $p)
                        <option value="{{ $p }}" @selected($period === $p)>{{ __('messages.transformation.per_period', ['period' => $p]) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">{{ __('messages.transformation.find_matches') }}</button>
        </form>

        @if ($matches->isNotEmpty())
            <div class="mt-8 grid grid-cols-1 gap-5 sm:grid-cols-2">
                @foreach ($matches as $match)
                    <x-supplier-card :company="$match" />
                @endforeach
            </div>
        @elseif ($species && $quantity)
            <div class="mt-8 rounded-2xl border border-dashed border-sand-300 dark:border-[#3a352e] p-10 text-center">
                <x-heroicon-o-building-office-2 class="mx-auto h-8 w-8 text-ink-soft" />
                <p class="mt-3 text-[1.0625rem] font-semibold text-ink dark:text-sand-100">{{ __('messages.transformation.no_match_title') }}</p>
                <p class="mt-1 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.transformation.no_match_body') }}</p>
            </div>
        @endif
    </div>

</x-layouts.app>
