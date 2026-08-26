<x-layouts.app
    :title="$term->term"
    :description="$term->meta_description ?: $term->definition"
    :schema="$schema"
    :breadcrumbs="$breadcrumbs">

    <div class="bg-white">
        <div class="mx-auto max-w-3xl px-4 py-8 lg:px-6">

            <nav aria-label="Breadcrumb">
                <ol class="flex flex-wrap items-center gap-2 text-[0.9375rem] text-ink-soft">
                    <li><a href="{{ route('home') }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">Home</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('glossary.index') }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">Glossary</a></li>
                    <li aria-hidden="true">/</li>
                    <li><span aria-current="page" class="font-medium text-ink">{{ $term->term }}</span></li>
                </ol>
            </nav>

            <h1 class="mt-3 text-[1.875rem] font-bold tracking-tight text-ink lg:text-[2.125rem]">{{ $term->term }}</h1>

            @if ($term->french_term)
                <p class="mt-1 text-[1.0625rem] text-ink-soft"><span class="font-semibold text-ink">French:</span> {{ $term->french_term }}</p>
            @endif

            <p class="mt-5 text-[1.125rem] leading-relaxed text-ink">{{ $term->definition }}</p>

            @if ($term->explanation)
                <div class="mt-4 text-[1.0625rem] leading-relaxed text-ink-soft">{{ $term->explanation }}</div>
            @endif

            @if ($relatedTerms->isNotEmpty())
                <section class="mt-8">
                    <h2 class="text-[1.125rem] font-bold text-forest-800">Related terms</h2>
                    <ul class="mt-2 flex flex-wrap gap-2">
                        @foreach ($relatedTerms as $related)
                            <li>
                                <a href="{{ $related->url() }}"
                                   class="inline-flex rounded-full border border-sand-300 px-3.5 py-1.5 text-[0.9375rem] font-medium text-forest-700 transition hover:border-forest-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">{{ $related->term }}</a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($relatedSpecies->isNotEmpty())
                <section class="mt-6">
                    <h2 class="text-[1.125rem] font-bold text-forest-800">Related species</h2>
                    <ul class="mt-2 flex flex-wrap gap-2">
                        @foreach ($relatedSpecies as $species)
                            <li>
                                <a href="{{ route('species.show', $species->slug) }}"
                                   class="inline-flex rounded-full border border-sand-300 px-3.5 py-1.5 text-[0.9375rem] font-medium text-forest-700 transition hover:border-forest-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">{{ $species->common_name }}</a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <p class="mt-8 text-[1rem]">
                <a href="{{ route('glossary.index') }}" class="font-semibold text-forest-700 transition hover:underline">← All glossary terms</a>
            </p>
        </div>
    </div>
</x-layouts.app>
