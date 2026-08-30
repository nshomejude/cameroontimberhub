@props(['product'])

@php
    $usd = $product->indicativeUsdPrice();
    $moq = $product->moqLabel();
@endphp

<section class="mx-4 mt-5 rounded-2xl bg-forest-50 px-4 py-4" aria-labelledby="m-price-heading">
    <h2 id="m-price-heading" class="sr-only">Price and minimum order</h2>

    <div class="flex items-stretch gap-4">
        <div class="min-w-0 flex-1">
            <p class="text-[1.125rem] text-ink-soft">Price (FOB)</p>

            @if ($product->price_amount !== null)
                <p class="mt-1 flex flex-wrap items-baseline gap-x-1.5 text-[1.625rem] font-bold leading-none text-ink">
                    {{ number_format((float) $product->price_amount) }}
                    <span class="text-[1rem] font-semibold text-forest-700">{{ $product->currencyLabel() }} / {{ $product->price_unit->label() }}</span>
                    <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-ink-soft"
                          title="Indicative FOB price quoted by the supplier. Final price is confirmed on quotation.">
                        <x-heroicon-o-information-circle class="h-5 w-5" />
                        <span class="sr-only">Indicative FOB price quoted by the supplier. Final price is confirmed on quotation.</span>
                    </span>
                </p>

                {{-- Optional, operator-configured indicative conversion. Hidden
                     entirely when no FX rate is configured. --}}
                @if ($usd !== null)
                    <p class="mt-1.5 text-[1.0625rem] text-ink-soft">(~ USD {{ $usd }} / {{ $product->price_unit->label() }} — indicative)</p>
                @endif

                <p class="mt-1 text-[0.9375rem] text-ink-soft/80">Supplier-reported price</p>
            @else
                <p class="mt-1 text-[1.25rem] font-bold text-forest-700">Price on request</p>
            @endif
        </div>

        @if ($moq)
            <div class="flex min-w-0 flex-1 items-start gap-2.5 border-l border-forest-200 pl-4">
                <x-heroicon-o-cube class="mt-0.5 h-7 w-7 shrink-0 text-forest-700" aria-hidden="true" />
                <div class="min-w-0">
                    <p class="text-[1.0625rem] leading-tight text-ink-soft">Minimum Order Quantity</p>
                    <p class="mt-1 text-[1.25rem] font-bold leading-none text-ink">{{ $moq }}</p>
                </div>
            </div>
        @endif
    </div>
</section>
