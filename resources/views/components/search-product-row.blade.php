@props(['product'])

@php
    use App\Enums\CompanyStatus;

    $company = $product->company;
    $verified = $company?->status === CompanyStatus::Verified;
    $image = $product->primaryImageUrl();
    $price = $product->priceLabel();
    $moq = $product->moqLabel();

    // "KD • FAS • 50mm" — only the parts this listing actually records.
    $spec = collect([
        $product->moisture_content,
        $product->grade,
        $product->thickness_mm ? rtrim(rtrim(number_format((float) $product->thickness_mm, 1), '0'), '.').'mm' : null,
    ])->filter()->take(3)->implode(' • ');

    $place = collect([$company?->city, $company?->region])->filter()->implode(', ');
@endphp

<article class="relative flex gap-0 overflow-hidden rounded-xl border border-sand-300/70 bg-white transition hover:border-forest-200 hover:shadow-md">

    {{-- Photo rail --}}
    <div class="relative w-[8.25rem] shrink-0 sm:w-40">
        @if ($image)
            <img src="{{ $image }}" alt="" loading="lazy" width="320" height="320"
                 class="h-full w-full object-cover">
        @else
            <div class="flex h-full min-h-[9rem] w-full items-center justify-center bg-sand-100 text-sand-400" aria-hidden="true">
                <x-heroicon-o-photo class="h-8 w-8" />
            </div>
        @endif

        @if ($product->is_best_seller)
            <span class="absolute left-0 top-2.5 rounded-r-md bg-forest-700 px-2.5 py-1 text-[0.875rem] font-semibold text-white">Best Seller</span>
        @elseif ($product->is_featured)
            <span class="absolute left-0 top-2.5 rounded-r-md bg-timber-700 px-2.5 py-1 text-[0.875rem] font-semibold text-white">Featured</span>
        @endif
    </div>

    {{-- Detail --}}
    <div class="flex min-w-0 flex-1 flex-col gap-1.5 p-3.5 sm:p-4">
        <div class="flex items-start justify-between gap-2">
            <h3 class="min-w-0 text-[1.0625rem] font-bold leading-snug text-ink">
                <a href="{{ route('products.show', $product->slug) }}"
                   class="after:absolute after:inset-0 hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                    {{ $product->name }}
                </a>
            </h3>

            <form method="POST" action="{{ route('rfq-list.store', $product->slug) }}" class="relative z-10 shrink-0">
                @csrf
                <button type="submit"
                        class="flex h-8 w-8 items-center justify-center rounded-full bg-white text-ink-soft shadow-sm ring-1 ring-sand-300/70 transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500"
                        aria-label="Add {{ $product->name }} to your RFQ list">
                    <x-heroicon-o-heart class="h-4 w-4" />
                </button>
            </form>
        </div>

        @if ($spec !== '')
            <p class="text-[1.0625rem] text-ink-soft">{{ $spec }}</p>
        @endif

        @if ($moq)
            <p class="text-[1.0625rem] text-ink-soft">MOQ: {{ $moq }}</p>
        @endif

        @if ($company)
            <p class="flex flex-wrap items-center gap-1 text-[1.0625rem] text-ink-soft">
                <span>Supplier:</span>
                <span class="font-semibold text-forest-700">{{ $company->trade_name ?: $company->legal_name }}</span>
                @if ($verified)
                    <x-heroicon-s-check-badge class="h-4 w-4 text-forest-600" aria-hidden="true" />
                    <span class="sr-only">Verified supplier</span>
                @endif
            </p>

            @if ($place !== '')
                <p class="flex items-center gap-1 text-[1.0625rem] text-ink-soft">
                    <x-heroicon-o-map-pin class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ $place }}
                </p>
            @endif
        @endif

        <div class="mt-1 flex flex-wrap items-end justify-between gap-2">
            @if ($verified)
                <span class="inline-flex items-center gap-1.5 rounded-md bg-forest-50 px-2 py-1 text-[0.9375rem] font-medium text-forest-700">
                    <x-heroicon-o-shield-check class="h-3.5 w-3.5" aria-hidden="true" /> Verified Supplier
                </span>
            @else
                <span></span>
            @endif

            <div class="flex flex-col items-end gap-2">
                @if ($price)
                    <p class="whitespace-nowrap text-[1.0625rem] font-bold text-forest-700">
                        {{ $price }}@if ($product->price_unit)<span class="ml-0.5 text-[0.9375rem] font-medium text-ink-soft">/{{ $product->price_unit->label() }}</span>@endif
                    </p>
                @else
                    <p class="text-[1.0625rem] font-medium text-ink-soft">Quote on request</p>
                @endif

                <a href="{{ route('rfq.create', ['product' => $product->slug]) }}"
                   class="relative z-10 inline-flex items-center justify-center rounded-lg bg-forest-800 px-4 py-2 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                    Request Quote
                </a>
            </div>
        </div>
    </div>
</article>
