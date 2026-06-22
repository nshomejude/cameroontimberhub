<x-layouts.app
    :title="$species->common_name . ' timber from Cameroon'"
    :description="$species->meta_description ?: Str::limit(strip_tags($species->description ?? ('Verified Cameroon exporters of ' . $species->common_name)), 160)"
    :schema="$schema">

    <section class="border-b border-stone-200 bg-white">
        <div class="mx-auto max-w-6xl px-4 py-10">
            <nav class="mb-4 text-sm text-stone-500">
                <a href="{{ route('species.index') }}" class="hover:text-amber-700">Species</a>
                <span class="mx-1">/</span>
                <span class="text-stone-700">{{ $species->common_name }}</span>
            </nav>
            <h1 class="text-3xl font-bold text-stone-900">{{ $species->common_name }}</h1>
            @if($species->scientific_name)
                <p class="mt-1 text-lg italic text-stone-500">{{ $species->scientific_name }}</p>
            @endif
            @if($species->is_cites_listed)
                <span class="mt-3 inline-block rounded bg-orange-100 px-2.5 py-1 text-xs font-medium text-orange-800">CITES listed{{ $species->cites_appendix ? ' — Appendix '.$species->cites_appendix : '' }}</span>
            @endif
        </div>
    </section>

    <div class="mx-auto grid max-w-6xl gap-8 px-4 py-10 lg:grid-cols-3">
        <div class="space-y-8 lg:col-span-2">
            @if($species->description)
                <section>
                    <h2 class="text-lg font-semibold text-stone-900">About {{ $species->common_name }}</h2>
                    <div class="mt-3 whitespace-pre-line text-stone-700">{{ $species->description }}</div>
                </section>
            @endif

            @if(is_array($species->characteristics) && count($species->characteristics))
                <section>
                    <h2 class="text-lg font-semibold text-stone-900">Properties</h2>
                    <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                        @foreach($species->characteristics as $key => $value)
                            <div class="rounded border border-stone-200 bg-white px-3 py-2 text-sm">
                                <dt class="font-medium text-stone-500">{{ Str::headline((string) $key) }}</dt>
                                <dd class="text-stone-800">{{ is_array($value) ? implode(', ', $value) : $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif
        </div>

        <aside>
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-5">
                <h2 class="font-semibold text-amber-900">Need {{ $species->common_name }}?</h2>
                <p class="mt-1 text-sm text-amber-800">Request a quote and we'll connect you with verified exporters.</p>
                <a href="#" class="mt-3 inline-block rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Request a quote</a>
            </div>
        </aside>
    </div>

    <section class="mx-auto max-w-6xl px-4 pb-14">
        <h2 class="text-xl font-bold text-stone-900">Verified exporters handling {{ $species->common_name }}</h2>
        @if($companies->isNotEmpty())
            <div class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($companies as $company)
                    @include('public.partials.company-card', ['company' => $company])
                @endforeach
            </div>
        @else
            <div class="mt-5 rounded-lg border border-dashed border-stone-300 bg-white p-10 text-center text-stone-600">
                <p>We're onboarding verified exporters for {{ $species->common_name }}.</p>
                <a href="#" class="mt-3 inline-block rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Submit an inquiry</a>
            </div>
        @endif
    </section>
</x-layouts.app>
