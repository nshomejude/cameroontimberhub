{{-- Functional placeholder: replaced by the pixel-perfect search results design. --}}
<x-layouts.app
    :title="$q !== '' ? 'Search results for '.$q : 'Search'"
    description="Search timber products, verified exporters and species on Cameroon Timber Hub.">

    <section class="mx-auto max-w-6xl px-4 py-10">
        <h1 class="font-display text-3xl font-semibold text-forest-950 dark:text-sand-100">Search</h1>

        <form method="GET" action="{{ route('search') }}" class="mt-6 flex gap-3">
            <input type="search" name="q" value="{{ $q }}" placeholder="Search products, suppliers, species"
                   class="w-full max-w-md rounded-lg border border-sand-300 px-3 py-2 text-sm dark:bg-[#1f1d18]">
            <button type="submit" class="rounded-lg bg-forest-800 px-5 py-2 text-sm font-semibold text-white">Search</button>
        </form>

        @if($q !== '')
            <h2 class="mt-10 font-display text-xl font-semibold text-forest-950 dark:text-sand-100">Products ({{ $products->count() }})</h2>
            <ul class="mt-3 space-y-2">
                @forelse($products as $product)
                    <li><a class="hover:underline" href="{{ route('products.show', $product->slug) }}">{{ $product->name }}</a>
                        <span class="text-sm text-ink-soft dark:text-[#b3ab9b]">— {{ $product->company?->trade_name ?: $product->company?->legal_name }}</span></li>
                @empty
                    <li class="text-ink-soft dark:text-[#b3ab9b]">No matching products.</li>
                @endforelse
            </ul>

            <h2 class="mt-10 font-display text-xl font-semibold text-forest-950 dark:text-sand-100">Suppliers ({{ $companies->count() }})</h2>
            <ul class="mt-3 space-y-2">
                @forelse($companies as $company)
                    <li><a class="hover:underline" href="{{ route('companies.show', $company->slug) }}">{{ $company->trade_name ?: $company->legal_name }}</a></li>
                @empty
                    <li class="text-ink-soft dark:text-[#b3ab9b]">No matching suppliers.</li>
                @endforelse
            </ul>

            <h2 class="mt-10 font-display text-xl font-semibold text-forest-950 dark:text-sand-100">Species ({{ $species->count() }})</h2>
            <ul class="mt-3 space-y-2">
                @forelse($species as $sp)
                    <li><a class="hover:underline" href="{{ route('species.show', $sp->slug) }}">{{ $sp->common_name }}</a></li>
                @empty
                    <li class="text-ink-soft dark:text-[#b3ab9b]">No matching species.</li>
                @endforelse
            </ul>
        @else
            <p class="mt-10 text-ink-soft dark:text-[#b3ab9b]">Enter a search term above.</p>
        @endif
    </section>
</x-layouts.app>
