<x-layouts.app
    :title="__('messages.company.portfolio_title', ['name' => $company->name])"
    type="profile"
    :description="__('messages.company.portfolio_intro')">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-6xl px-4 py-12">
            <a href="{{ route('companies.show', $company->slug) }}" class="text-sm font-medium text-forest-700 hover:underline">&larr; {{ __('messages.company.back_to', ['name' => $company->name]) }}</a>
            <p class="eyebrow mt-4">{{ __('messages.company.portfolio') }}</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl">{{ $company->name }}</h1>
            <p class="mt-3 max-w-2xl text-lg text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.company.portfolio_intro') }}</p>
        </div>
    </section>

    <div class="mx-auto max-w-6xl px-4 py-10">
        @if ($items->isEmpty())
            <div class="rounded-2xl border border-dashed border-sand-300 dark:border-[#3a352e] p-10 text-center">
                <x-heroicon-o-photo class="mx-auto h-8 w-8 text-ink-soft" />
                <p class="mt-3 text-[1.0625rem] font-semibold text-ink dark:text-sand-100">{{ __('messages.company.no_portfolio') }}</p>
                <p class="mt-1 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.company.portfolio_check_back') }}</p>
            </div>
        @else
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($items as $item)
                    <article class="overflow-hidden rounded-xl border border-sand-200 dark:border-[#2c2a24] bg-white dark:bg-[#1f1d18] transition hover:shadow-lg">
                        <img src="{{ asset('storage/'.$item->image_path) }}" alt="{{ $item->alt_text ?? $item->caption }}" class="h-48 w-full object-cover">
                        <div class="p-4">
                            <h2 class="text-[1.0625rem] font-semibold text-ink dark:text-sand-100">{{ $item->caption }}</h2>
                            @if ($item->description)
                                <p class="mt-1 text-sm text-ink-soft dark:text-[#b3ab9b]">{{ $item->description }}</p>
                            @endif
                            <dl class="mt-3 space-y-1 text-xs text-ink-soft dark:text-[#8f887b]">
                                @if ($item->materials_used)
                                    <div><dt class="inline font-medium">{{ __('messages.company.materials') }}</dt> <dd class="inline">{{ $item->materials_used }}</dd></div>
                                @endif
                                @if ($item->completed_on)
                                    <div><dt class="inline font-medium">{{ __('messages.company.completed') }}</dt> <dd class="inline">{{ $item->completed_on->format('M Y') }}</dd></div>
                                @endif
                            </dl>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
