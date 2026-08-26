@props(['company'])

@php
    $profileUrl = route('companies.show', $company->slug);
    $verified = $company->status === \App\Enums\CompanyStatus::Verified;

    $specialisations = $company->relationLoaded('products')
        ? $company->products->map(fn ($p) => $p->product_type?->label())->filter()->unique()->take(6)->values()
        : collect();

    if ($specialisations->isEmpty() && $company->relationLoaded('species')) {
        $specialisations = $company->species->pluck('common_name')->take(6)->values();
    }

    $location = collect([$company->city, $company->region ? $company->region.' Region' : null])->filter()->implode(', ');

    $stats = collect([
        ['label' => 'Products', 'value' => ($company->products_count ?? null) ? $company->products_count : null],
        ['label' => 'Response Rate', 'value' => $company->response_rate_percent !== null ? $company->response_rate_percent.'%' : null],
        ['label' => 'Experience', 'value' => $company->years_experience !== null ? $company->years_experience.' Yrs' : null],
        ['label' => 'Markets', 'value' => $company->relationLoaded('exportMarkets') && $company->exportMarkets->isNotEmpty() ? $company->exportMarkets->count() : null],
    ])->filter(fn (array $s) => $s['value'] !== null)->values();
@endphp

{{-- List view: a genuinely different layout — wide landscape thumbnail, the
     full company description, and the stats as an inline row rather than a
     three-up strip. --}}
<article class="relative flex gap-5 rounded-xl border border-sand-300/70 bg-white p-4 transition hover:border-forest-200 hover:shadow-md">

    <div class="relative hidden w-56 shrink-0 sm:block">
        <img src="{{ $company->coverUrl() }}" alt="" loading="lazy" width="640" height="360"
             class="aspect-[16/10] w-full rounded-lg object-cover">
        <img src="{{ $company->logoUrl() }}" alt="" loading="lazy" width="136" height="136"
             class="absolute -bottom-3 left-3 h-12 w-12 rounded-full bg-white object-cover ring-4 ring-white">
        @if ($company->is_featured)
            <span class="absolute left-0 top-2 rounded-r-md bg-forest-700 px-2 py-0.5 text-[0.8125rem] font-semibold text-white">Featured</span>
        @endif
    </div>

    <div class="min-w-0 flex-1">
        <div class="flex items-start gap-3">
            <div class="min-w-0 flex-1">
                <h3 class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[1.0625rem] font-bold leading-tight text-ink">
                    <a href="{{ $profileUrl }}" class="rounded transition after:absolute after:inset-0 hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500">
                        {{ $company->name }}
                    </a>
                    @if ($verified)
                        <span class="inline-flex items-center rounded-full bg-forest-700 px-2 py-0.5 text-[0.8125rem] font-semibold text-white">Verified</span>
                    @endif
                    @if ($company->supplier_type)
                        <span class="inline-flex items-center rounded-full bg-sand-200 px-2 py-0.5 text-[0.8125rem] font-semibold text-ink-soft">{{ $company->supplier_type->label() }}</span>
                    @endif
                </h3>

                @if ($location !== '')
                    <p class="mt-1 flex items-center gap-1 text-[1.0625rem] text-ink-soft">
                        <x-heroicon-s-map-pin class="h-3.5 w-3.5 shrink-0 text-forest-600" />
                        {{ $location }}
                    </p>
                @endif
            </div>

            <button type="button"
                    class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-sand-300 text-ink-soft transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500"
                    aria-label="Save {{ $company->name }} to favourites">
                <x-heroicon-o-heart class="h-4 w-4" />
            </button>
        </div>

        @if ($company->description)
            <p class="mt-2 line-clamp-2 text-[1.0625rem] leading-relaxed text-ink-soft">{{ $company->description }}</p>
        @endif

        @if ($specialisations->isNotEmpty())
            <ul class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($specialisations as $item)
                    <li class="rounded-full bg-sand-100 px-2.5 py-1 text-[0.875rem] font-medium text-ink-soft">{{ $item }}</li>
                @endforeach
            </ul>
        @endif

        <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2">
            @if ($stats->isNotEmpty())
                <dl class="flex flex-wrap items-center gap-x-6 gap-y-1">
                    @foreach ($stats as $stat)
                        <div class="flex items-baseline gap-1.5">
                            <dd class="text-[1.125rem] font-bold text-ink">{{ $stat['value'] }}</dd>
                            <dt class="text-[0.875rem] text-ink-soft">{{ $stat['label'] }}</dt>
                        </div>
                    @endforeach
                </dl>
            @endif

            <a href="{{ $profileUrl }}#contact"
               class="relative z-10 ml-auto rounded-lg bg-forest-700 px-4 py-2 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                Contact Supplier
            </a>
        </div>
    </div>
</article>
