<x-layouts.app
    title="Verified timber exporters in Cameroon"
    description="Browse verified Cameroonian timber exporters by species, region, and export market. Documents reviewed by Cameroon Timber Hub.">

    <section class="border-b border-stone-200 bg-white">
        <div class="mx-auto max-w-6xl px-4 py-10">
            <h1 class="text-3xl font-bold text-stone-900">Verified timber exporters in Cameroon</h1>
            <p class="mt-2 text-stone-600">
                {{ $companies->total() }} verified {{ Str::plural('exporter', $companies->total()) }}.
            </p>

            <form method="GET" action="{{ route('directory') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <input type="search" name="q" value="{{ $filters['term'] }}" placeholder="Search exporters…"
                       class="rounded-md border border-stone-300 px-3 py-2 text-sm">

                <select name="region" class="rounded-md border border-stone-300 px-3 py-2 text-sm">
                    <option value="">All regions</option>
                    @foreach($regions as $region)
                        <option value="{{ $region }}" @selected($filters['region'] === $region)>{{ $region }}</option>
                    @endforeach
                </select>

                <select name="species" class="rounded-md border border-stone-300 px-3 py-2 text-sm">
                    <option value="">All species</option>
                    @foreach($speciesList as $sp)
                        <option value="{{ $sp->slug }}" @selected($filters['speciesSlug'] === $sp->slug)>{{ $sp->common_name }}</option>
                    @endforeach
                </select>

                <select name="market" class="rounded-md border border-stone-300 px-3 py-2 text-sm">
                    <option value="">All export markets</option>
                    @foreach($markets as $code)
                        <option value="{{ $code }}" @selected($filters['market'] === $code)>{{ $code }}</option>
                    @endforeach
                </select>

                <div class="flex gap-2 lg:col-span-4">
                    <button type="submit" class="rounded-md bg-amber-600 px-5 py-2 text-sm font-semibold text-white hover:bg-amber-700">Filter</button>
                    <a href="{{ route('directory') }}" class="rounded-md border border-stone-300 px-5 py-2 text-sm font-medium text-stone-600 hover:border-amber-600">Reset</a>
                </div>
            </form>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-10">
        @if($companies->isNotEmpty())
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($companies as $company)
                    @include('public.partials.company-card', ['company' => $company])
                @endforeach
            </div>
            <div class="mt-8">{{ $companies->links() }}</div>
        @else
            <div class="rounded-lg border border-dashed border-stone-300 bg-white p-12 text-center">
                <p class="text-lg font-medium text-stone-700">No verified exporters match these filters yet.</p>
                <p class="mt-2 text-stone-500">Try broadening your search, or tell us what you need and we'll connect you.</p>
                <div class="mt-5 flex justify-center gap-3">
                    <a href="{{ route('directory') }}" class="rounded-md border border-stone-300 px-5 py-2 text-sm font-medium text-stone-600 hover:border-amber-600">Reset filters</a>
                    <a href="#" class="rounded-md bg-amber-600 px-5 py-2 text-sm font-semibold text-white hover:bg-amber-700">Request a quote</a>
                </div>
            </div>
        @endif
    </section>
</x-layouts.app>
