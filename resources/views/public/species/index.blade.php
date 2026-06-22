<x-layouts.app
    title="Cameroon timber species"
    description="Explore the hardwood and timber species exported from Cameroon — Sapele, Iroko, Ayous, Tali, Padouk and more — and find verified exporters.">

    <section class="border-b border-stone-200 bg-white">
        <div class="mx-auto max-w-6xl px-4 py-10">
            <h1 class="text-3xl font-bold text-stone-900">Cameroon timber species</h1>
            <p class="mt-2 max-w-2xl text-stone-600">Browse the species exported from Cameroon and find verified exporters handling each one.</p>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-10">
        @if($species->isNotEmpty())
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($species as $sp)
                    <a href="{{ route('species.show', $sp->slug) }}" class="block rounded-lg border border-stone-200 bg-white p-5 transition hover:border-amber-500 hover:shadow-sm">
                        <h2 class="font-semibold text-stone-900">{{ $sp->common_name }}</h2>
                        @if($sp->scientific_name)
                            <p class="text-sm italic text-stone-500">{{ $sp->scientific_name }}</p>
                        @endif
                        @if($sp->description)
                            <p class="mt-2 text-sm text-stone-600">{{ Str::limit(strip_tags($sp->description), 120) }}</p>
                        @endif
                        @if($sp->is_cites_listed)
                            <span class="mt-3 inline-block rounded bg-orange-100 px-2 py-0.5 text-xs font-medium text-orange-800">CITES{{ $sp->cites_appendix ? ' '.$sp->cites_appendix : '' }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
            <div class="mt-8">{{ $species->links() }}</div>
        @else
            <div class="rounded-lg border border-dashed border-stone-300 bg-white p-12 text-center text-stone-600">
                The species catalog is being prepared.
            </div>
        @endif
    </section>
</x-layouts.app>
