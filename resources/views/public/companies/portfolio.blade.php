<x-layouts.app
    :title="$company->name.' — Portfolio'"
    :description="'Completed work from '.$company->name.'.'">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-6xl px-4 py-12">
            <a href="{{ route('companies.show', $company->slug) }}" class="text-sm font-medium text-forest-700 hover:underline">&larr; Back to {{ $company->name }}</a>
            <p class="eyebrow mt-4">Portfolio</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl">{{ $company->name }}</h1>
            <p class="mt-3 max-w-2xl text-lg text-ink-soft dark:text-[#b3ab9b]">Completed work from this artisan.</p>
        </div>
    </section>

    <div class="mx-auto max-w-6xl px-4 py-10">
        @if ($items->isEmpty())
            <div class="rounded-2xl border border-dashed border-sand-300 dark:border-[#3a352e] p-10 text-center">
                <x-heroicon-o-photo class="mx-auto h-8 w-8 text-ink-soft" />
                <p class="mt-3 text-[1.0625rem] font-semibold text-ink dark:text-sand-100">No portfolio pieces yet</p>
                <p class="mt-1 text-[0.9375rem] text-ink-soft dark:text-[#b3ab9b]">Check back soon — this page will show completed work as it's added.</p>
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
                                    <div><dt class="inline font-medium">Materials:</dt> <dd class="inline">{{ $item->materials_used }}</dd></div>
                                @endif
                                @if ($item->completed_on)
                                    <div><dt class="inline font-medium">Completed:</dt> <dd class="inline">{{ $item->completed_on->format('M Y') }}</dd></div>
                                @endif
                            </dl>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
