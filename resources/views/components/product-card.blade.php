@props([
    'product',
    'compact' => false,
])

@php
    $url = route('products.show', $product->slug);
    $image = $product->primaryImageUrl();
    $company = $product->company;
    $verified = $company?->status === \App\Enums\CompanyStatus::Verified;
    $price = $product->priceLabel();
    $moq = $product->moqLabel();
@endphp

<article class="group relative flex flex-col overflow-hidden rounded-xl border border-sand-300/70 bg-white transition hover:border-forest-200 hover:shadow-lg">

    <div class="relative">
        @if ($image)
            <img src="{{ $image }}" alt="" loading="lazy" width="480" height="360"
                 class="aspect-[4/3] w-full object-cover">
        @else
            {{-- No photograph on file: a neutral tile rather than another
                 product's picture. Decorative, so hidden from assistive tech. --}}
            <div class="flex aspect-[4/3] w-full items-center justify-center bg-sand-100 text-sand-400" aria-hidden="true">
                <x-heroicon-o-photo class="h-10 w-10" />
            </div>
        @endif

        @if ($product->is_best_seller)
            <span class="absolute left-0 top-3 rounded-r-md bg-forest-700 py-1 pl-3 pr-3 text-[0.875rem] font-semibold text-white">Best Seller</span>
        @elseif ($product->is_featured)
            <span class="absolute left-0 top-3 rounded-r-md bg-timber-700 py-1 pl-3 pr-3 text-[0.875rem] font-semibold text-white">Featured</span>
        @endif

        <form method="POST" action="{{ route('rfq-list.store', $product->slug) }}"
              class="absolute right-2.5 top-2.5 z-10">
            @csrf
            <button type="submit"
                    class="flex h-8 w-8 items-center justify-center rounded-full bg-white/95 text-ink-soft shadow-sm transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500"
                    aria-label="Add {{ $product->name }} to your RFQ list">
                <x-heroicon-o-heart class="h-4 w-4" />
            </button>
        </form>
    </div>

    <div class="flex flex-1 flex-col {{ $compact ? 'px-3 pb-3 pt-3' : 'px-4 pb-4 pt-4' }}">
        <h3 class="{{ $compact ? 'text-[1.0625rem]' : 'text-[1rem]' }} font-bold leading-tight text-ink">
            <a href="{{ $url }}" class="rounded transition after:absolute after:inset-0 hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500">
                {{ $product->name }}
            </a>
        </h3>

        @if ($price)
            <p class="mt-1.5 {{ $compact ? 'text-[1.125rem]' : 'text-[1.0625rem]' }} font-bold text-forest-700">
                {{ number_format((float) $product->price_amount) }}
                <span class="text-[0.9375rem] font-semibold text-ink-soft">{{ $product->currencyLabel() }} /{{ $product->price_unit->label() }}</span>
            </p>
        @else
            <p class="mt-1.5 text-[1.0625rem] font-semibold text-ink-soft">Price on request</p>
        @endif

        @if ($moq)
            <p class="mt-1 text-[0.9375rem] text-ink-soft">MOQ: {{ $moq }}</p>
        @endif

        @if ($company)
            <p class="mt-auto flex items-center gap-1.5 pt-3 text-[0.9375rem] text-ink-soft">
                <img src="{{ $company->logoUrl() }}" alt="" loading="lazy" width="32" height="32"
                     class="h-4 w-4 shrink-0 rounded-full object-cover">
                <span class="truncate">{{ $company->name }}</span>
                @if ($verified)
                    <x-heroicon-s-check-badge class="h-3.5 w-3.5 shrink-0 text-forest-600" />
                    <span class="sr-only">Verified supplier</span>
                @endif
            </p>
        @endif
    </div>
</article>
