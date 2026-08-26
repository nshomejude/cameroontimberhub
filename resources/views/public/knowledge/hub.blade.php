<x-layouts.app
    :title="$hub->label()"
    :description="$hub->description()"
    :breadcrumbs="$breadcrumbs"
    :schema="$schema">

    <div class="bg-white">
        <div class="mx-auto max-w-4xl px-4 py-8 lg:px-6">

            <nav aria-label="Breadcrumb">
                <ol class="flex flex-wrap items-center gap-2 text-[0.9375rem] text-ink-soft">
                    <li><a href="{{ route('home') }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">Home</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('knowledge.index') }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">Knowledge Centre</a></li>
                    <li aria-hidden="true">/</li>
                    <li><span aria-current="page" class="font-medium text-ink">{{ $hub->label() }}</span></li>
                </ol>
            </nav>

            <p class="eyebrow mt-3">Knowledge Centre</p>
            <h1 class="mt-1 text-[1.875rem] font-bold tracking-tight text-ink lg:text-[2.125rem]">{{ $hub->label() }}</h1>
            <p class="mt-1.5 max-w-2xl text-[1.125rem] leading-relaxed text-ink-soft">{{ $hub->description() }}</p>

            {{-- Authored pillar copy, when a content/knowledge/{hub}.md file exists.
                 Safe to echo raw: ArticleBody::render() renders markdown with
                 html_input => escape, so no author HTML survives. --}}
            @if ($pillar)
                <div class="article-prose mt-6">{!! $pillar !!}</div>
            @endif

            <h2 class="mt-10 text-[1.25rem] font-bold text-forest-800">Articles in this hub</h2>

            @if ($articles->isNotEmpty())
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach ($articles as $entry)
                        <x-article-card :article="$entry" />
                    @endforeach
                </div>
            @else
                <div class="mt-4 rounded-xl border border-dashed border-sand-300 bg-sand-50 px-6 py-12 text-center">
                    <h3 class="text-[1.25rem] font-bold text-ink">Nothing published in this hub yet</h3>
                    <p class="mx-auto mt-2 max-w-md text-[1.0625rem] text-ink-soft">
                        Browse the other <a href="{{ route('knowledge.index') }}" class="font-semibold text-forest-700 transition hover:underline">Knowledge Centre hubs</a>,
                        the <a href="{{ route('glossary.index') }}" class="font-semibold text-forest-700 transition hover:underline">glossary</a>,
                        or the latest <a href="{{ route('insights.index') }}" class="font-semibold text-forest-700 transition hover:underline">insights</a>.
                    </p>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
