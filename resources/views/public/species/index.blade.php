<x-layouts.app
    title="Cameroon timber species"
    description="Explore the hardwood and timber species exported from Cameroon — Sapele, Iroko, Ayous, Tali, Padouk and more — and find verified exporters.">

    <section class="border-b border-sand-200 bg-gradient-to-b from-forest-50 to-sand-50">
        <div class="mx-auto max-w-6xl px-4 py-14">
            <p class="eyebrow">Species catalog</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 sm:text-5xl">Cameroon timber species</h1>
            <p class="mt-3 max-w-2xl text-ink-soft">Browse the species exported from Cameroon and find verified exporters handling each one.</p>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-12">
        @if($species->isNotEmpty())
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($species as $sp)
                    <a href="{{ route('species.show', $sp->slug) }}" class="group flex flex-col rounded-2xl border border-sand-200 bg-white p-6 shadow-[0_1px_0_rgba(0,0,0,0.02)] transition hover:-translate-y-0.5 hover:border-forest-200 hover:shadow-lg">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="font-display text-xl font-semibold text-forest-900 group-hover:text-forest-700">{{ $sp->common_name }}</h2>
                                @if($sp->scientific_name)
                                    <p class="text-sm italic text-ink-soft">{{ $sp->scientific_name }}</p>
                                @endif
                            </div>
                            @if($sp->is_cites_listed)
                                <span class="shrink-0 rounded-full bg-timber-100 px-2.5 py-0.5 text-xs font-semibold text-timber-800">CITES{{ $sp->cites_appendix ? ' '.$sp->cites_appendix : '' }}</span>
                            @endif
                        </div>
                        @if($sp->description)
                            <p class="mt-3 text-sm leading-relaxed text-ink-soft">{{ Str::limit(strip_tags($sp->description), 120) }}</p>
                        @endif
                        <span class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-forest-600">
                            View exporters <x-heroicon-m-arrow-right class="h-4 w-4 transition group-hover:translate-x-0.5" />
                        </span>
                    </a>
                @endforeach
            </div>
            <div class="mt-10">{{ $species->links() }}</div>
        @else
            <div class="rounded-2xl border border-dashed border-sand-300 bg-white p-14 text-center text-ink-soft">
                The species catalog is being prepared.
            </div>
        @endif
    </section>
</x-layouts.app>
