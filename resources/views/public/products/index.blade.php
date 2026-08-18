{{-- Functional placeholder: replaced by the pixel-perfect marketplace design. --}}
<x-layouts.app
    title="Timber marketplace — Cameroon hardwood products"
    description="Browse sawn timber, logs, veneer, flooring and decking from verified Cameroon exporters.">

    <section class="mx-auto max-w-6xl px-4 py-10">
        <h1 class="font-display text-3xl font-semibold text-forest-950 dark:text-sand-100">Marketplace</h1>
        <p class="mt-2 text-ink-soft dark:text-[#b3ab9b]">Timber products from verified Cameroon exporters.</p>

        <form method="GET" action="{{ route('marketplace') }}" class="mt-6 flex flex-wrap gap-3">
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search products"
                   class="rounded-lg border border-sand-300 px-3 py-2 text-sm dark:bg-[#1f1d18]">

            <select name="species" class="rounded-lg border border-sand-300 px-3 py-2 text-sm dark:bg-[#1f1d18]">
                <option value="">All species</option>
                @foreach($speciesOptions as $sp)
                    <option value="{{ $sp->slug }}" @selected(($filters['species'] ?? '') === $sp->slug)>{{ $sp->common_name }}</option>
                @endforeach
            </select>

            <select name="type" class="rounded-lg border border-sand-300 px-3 py-2 text-sm dark:bg-[#1f1d18]">
                <option value="">All types</option>
                @foreach(\App\Enums\ProductType::options() as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>

            <button type="submit" class="rounded-lg bg-forest-800 px-5 py-2 text-sm font-semibold text-white">Filter</button>
        </form>

        @if($products->isNotEmpty())
            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($products as $product)
                    <a href="{{ route('products.show', $product->slug) }}"
                       class="rounded-2xl border border-sand-200 bg-white p-5 transition hover:border-forest-200 hover:shadow-lg dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                        <h2 class="font-display text-lg font-semibold text-forest-900 dark:text-sand-100">{{ $product->name }}</h2>
                        <p class="mt-1 text-xs uppercase tracking-wide text-ink-soft dark:text-[#b3ab9b]">{{ $product->product_type->label() }}</p>
                        @if($product->price_amount !== null)
                            <p class="mt-2 font-semibold text-forest-700 dark:text-forest-400">
                                {{ number_format((float) $product->price_amount) }} {{ $product->price_currency }} / {{ $product->price_unit->label() }}
                            </p>
                        @endif
                        @if($product->moq_quantity !== null)
                            <p class="text-sm text-ink-soft dark:text-[#b3ab9b]">MOQ: {{ rtrim(rtrim(number_format((float) $product->moq_quantity, 2), '0'), '.') }} {{ $product->moq_unit->label() }}</p>
                        @endif
                        <p class="mt-2 text-sm text-ink-soft dark:text-[#b3ab9b]">{{ $product->company?->trade_name ?: $product->company?->legal_name }}</p>
                        @if($product->species)
                            <p class="text-sm text-ink-soft dark:text-[#b3ab9b]">{{ $product->species->common_name }}</p>
                        @endif
                    </a>
                @endforeach
            </div>
            <div class="mt-10">{{ $products->links() }}</div>
        @else
            <p class="mt-10 rounded-2xl border border-dashed border-sand-300 p-14 text-center text-ink-soft dark:border-[#3a352e] dark:text-[#b3ab9b]">
                No products match your search.
            </p>
        @endif
    </section>
</x-layouts.app>
