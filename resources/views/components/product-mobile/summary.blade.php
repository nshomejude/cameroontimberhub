@props(['product'])

@php
    $company = $product->company;
    $species = $product->species;
    $verified = $company?->status === \App\Enums\CompanyStatus::Verified;

    // Four spec chips — each rendered only when the listing really holds the value.
    $chips = collect([
        ['icon' => 'sparkles', 'label' => 'Species', 'value' => $species?->common_name],
        ['icon' => 'squares-2x2', 'label' => 'Form', 'value' => $product->product_type->label()],
        ['icon' => 'check-badge', 'label' => 'Grade', 'value' => $product->grade],
        ['icon' => 'arrows-up-down', 'label' => 'Thickness', 'value' => $product->thickness_mm !== null
            ? rtrim(rtrim(number_format((float) $product->thickness_mm, 2), '0'), '.').'mm'
            : null],
    ])->filter(fn ($c) => filled($c['value']))->values();
@endphp

<section class="px-4 pt-5">
    <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
        <h1 class="min-w-0 flex-1 text-[1.625rem] font-bold leading-tight tracking-tight text-ink">{{ $product->name }}</h1>

        @if ($verified)
            <span class="mt-1 inline-flex shrink-0 items-center gap-1.5 rounded-full bg-forest-50 px-3 py-1.5 text-[0.8125rem] font-semibold text-forest-800">
                <x-heroicon-o-check-badge class="h-4 w-4" />
                Verified Supplier
            </span>
        @endif
    </div>

    {{-- Supplier-authored strapline. Absent when the column is null. --}}
    @if (filled($product->tagline))
        <p class="mt-1 text-[1.0625rem] text-ink-soft">{{ $product->tagline }}</p>
    @endif

    @if (filled($product->description))
        <p class="mt-3 text-[0.9375rem] leading-relaxed text-ink-soft">{{ \Illuminate\Support\Str::limit(strip_tags((string) $product->description), 260) }}</p>
    @endif

    @if ($chips->isNotEmpty())
        <ul class="mt-4 grid grid-cols-2 gap-3" role="list">
            @foreach ($chips as $chip)
                <li class="flex items-center gap-2.5 rounded-xl border border-sand-200 bg-sand-100 px-3 py-2.5">
                    <x-dynamic-component :component="'heroicon-o-'.$chip['icon']" class="h-5 w-5 shrink-0 text-forest-700" aria-hidden="true" />
                    <div class="min-w-0">
                        <p class="text-[0.75rem] leading-tight text-ink-soft">{{ $chip['label'] }}</p>
                        <p class="truncate text-[0.9375rem] font-semibold leading-snug text-ink">{{ $chip['value'] }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
