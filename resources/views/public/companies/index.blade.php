<x-layouts.app
    title="Verified timber exporters in Cameroon"
    description="Browse verified Cameroonian timber exporters by species, region, and export market. Documents reviewed by Cameroon Timber Hub.">

    <section class="border-b border-sand-200 bg-gradient-to-b from-forest-50 to-sand-50">
        <div class="mx-auto max-w-6xl px-4 py-14">
            <p class="eyebrow">Verified directory</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 sm:text-5xl">Timber exporters in Cameroon</h1>
            <p class="mt-3 text-ink-soft">
                {{ $companies->total() }} verified {{ Str::plural('exporter', $companies->total()) }} — filter by species, region and export market.
            </p>

            <form method="GET" action="{{ route('directory') }}" class="mt-8 rounded-2xl border border-sand-200 bg-white p-4 shadow-sm">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="relative block">
                        <x-heroicon-m-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-soft/60" />
                        <input type="search" name="q" value="{{ $filters['term'] }}" placeholder="Search exporters…"
                               class="w-full rounded-lg border border-sand-300 bg-sand-50/60 py-2.5 pl-10 pr-3 text-sm text-ink placeholder:text-ink-soft/60 focus:border-forest-500 focus:bg-white focus:ring-2 focus:ring-forest-100 focus:outline-none">
                    </label>

                    <select name="region" class="rounded-lg border border-sand-300 bg-sand-50/60 px-3 py-2.5 text-sm text-ink focus:border-forest-500 focus:bg-white focus:ring-2 focus:ring-forest-100 focus:outline-none">
                        <option value="">All regions</option>
                        @foreach($regions as $region)
                            <option value="{{ $region }}" @selected($filters['region'] === $region)>{{ $region }}</option>
                        @endforeach
                    </select>

                    <select name="species" class="rounded-lg border border-sand-300 bg-sand-50/60 px-3 py-2.5 text-sm text-ink focus:border-forest-500 focus:bg-white focus:ring-2 focus:ring-forest-100 focus:outline-none">
                        <option value="">All species</option>
                        @foreach($speciesList as $sp)
                            <option value="{{ $sp->slug }}" @selected($filters['speciesSlug'] === $sp->slug)>{{ $sp->common_name }}</option>
                        @endforeach
                    </select>

                    <select name="market" class="rounded-lg border border-sand-300 bg-sand-50/60 px-3 py-2.5 text-sm text-ink focus:border-forest-500 focus:bg-white focus:ring-2 focus:ring-forest-100 focus:outline-none">
                        <option value="">All export markets</option>
                        @foreach($markets as $code)
                            <option value="{{ $code }}" @selected($filters['market'] === $code)>{{ $code }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mt-3 flex items-center gap-2">
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">
                        <x-heroicon-m-funnel class="h-4 w-4" /> Apply filters
                    </button>
                    <a href="{{ route('directory') }}" class="rounded-full px-4 py-2.5 text-sm font-medium text-ink-soft transition hover:text-forest-700">Reset</a>
                </div>
            </form>
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
            <div class="rounded-2xl border border-dashed border-sand-300 bg-white p-14 text-center">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-forest-50 text-forest-600">
                    <x-heroicon-o-magnifying-glass class="h-6 w-6" />
                </span>
                <h2 class="mt-4 font-display text-xl font-semibold text-forest-900">No verified exporters match these filters yet</h2>
                <p class="mt-2 text-ink-soft">Try broadening your search, or tell us what you need and we'll connect you.</p>
                <div class="mt-6 flex justify-center gap-3">
                    <a href="{{ route('directory') }}" class="rounded-full border border-sand-300 px-5 py-2.5 text-sm font-medium text-ink-soft transition hover:border-forest-400 hover:text-forest-700">Reset filters</a>
                    <a href="#" class="rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">Request a quote</a>
                </div>
            </div>
        @endif
    </section>
</x-layouts.app>
