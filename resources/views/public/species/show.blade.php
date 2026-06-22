<x-layouts.app
    :title="$species->common_name . ' timber from Cameroon'"
    :description="$species->meta_description ?: Str::limit(strip_tags($species->description ?? ('Verified Cameroon exporters of ' . $species->common_name)), 160)"
    :schema="$schema">

    <section class="relative overflow-hidden border-b border-sand-200 bg-gradient-to-br from-forest-800 to-forest-950 text-sand-100">
        <x-brand-mark class="pointer-events-none absolute -right-16 -top-16 h-80 w-80 opacity-10" />
        <div class="relative mx-auto max-w-6xl px-4 py-12">
            <nav class="mb-6 flex items-center gap-1.5 text-sm text-forest-200">
                <a href="{{ route('species.index') }}" class="transition hover:text-white">Species</a>
                <x-heroicon-m-chevron-right class="h-4 w-4" />
                <span class="text-sand-100">{{ $species->common_name }}</span>
            </nav>
            <h1 class="font-display text-4xl font-semibold text-white sm:text-5xl">{{ $species->common_name }}</h1>
            @if($species->scientific_name)
                <p class="mt-2 text-lg italic text-forest-200">{{ $species->scientific_name }}</p>
            @endif
            @if($species->is_cites_listed)
                <span class="mt-4 inline-block rounded-full bg-timber-400/20 px-3 py-1 text-xs font-semibold text-timber-200 ring-1 ring-timber-400/40">CITES listed{{ $species->cites_appendix ? ' — Appendix '.$species->cites_appendix : '' }}</span>
            @endif
        </div>
    </section>

    <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 lg:grid-cols-3">
        <div class="space-y-10 lg:col-span-2">
            @if($species->description)
                <section>
                    <h2 class="font-display text-2xl font-semibold text-forest-950">About {{ $species->common_name }}</h2>
                    <div class="mt-4 whitespace-pre-line leading-relaxed text-ink-soft">{{ $species->description }}</div>
                </section>
            @endif

            @if(is_array($species->characteristics) && count($species->characteristics))
                <section>
                    <h2 class="font-display text-2xl font-semibold text-forest-950">Properties</h2>
                    <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                        @foreach($species->characteristics as $key => $value)
                            <div class="rounded-xl border border-sand-200 bg-white px-4 py-3">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-ink-soft">{{ Str::headline((string) $key) }}</dt>
                                <dd class="mt-0.5 text-ink">{{ is_array($value) ? implode(', ', $value) : $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif
        </div>

        <aside>
            <div class="rounded-2xl bg-forest-800 p-6 text-sand-100">
                <h2 class="font-display text-lg font-semibold text-white">Need {{ $species->common_name }}?</h2>
                <p class="mt-1 text-sm text-forest-200">Request a quote and we'll connect you with verified exporters.</p>
                <a href="#" class="mt-4 inline-flex items-center gap-1.5 rounded-full bg-timber-400 px-5 py-2.5 text-sm font-semibold text-forest-950 transition hover:bg-timber-300">
                    Request a quote <x-heroicon-m-arrow-right class="h-4 w-4" />
                </a>
            </div>
        </aside>
    </div>

    <section class="mx-auto max-w-6xl px-4 pb-16">
        <h2 class="font-display text-2xl font-semibold text-forest-950">Verified exporters handling {{ $species->common_name }}</h2>
        @if($companies->isNotEmpty())
            <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($companies as $company)
                    @include('public.partials.company-card', ['company' => $company])
                @endforeach
            </div>
        @else
            <div class="mt-6 rounded-2xl border border-dashed border-sand-300 bg-white p-12 text-center">
                <p class="text-ink-soft">We're onboarding verified exporters for {{ $species->common_name }}.</p>
                <a href="#" class="mt-4 inline-flex items-center gap-1.5 rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">
                    Submit an inquiry <x-heroicon-m-arrow-right class="h-4 w-4" />
                </a>
            </div>
        @endif
    </section>
</x-layouts.app>
