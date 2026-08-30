@props([
    'company',
    'compact' => false,
])

@php
    $profileUrl = route('companies.show', $company->slug);
    $verified = $company->status === \App\Enums\CompanyStatus::Verified;

    // Specialisations: the product forms this supplier actually lists, falling
    // back to the species it handles. Never invented.
    $specialisations = $company->relationLoaded('products')
        ? $company->products->map(fn ($p) => $p->product_type?->label())->filter()->unique()->take(4)->values()
        : collect();

    if ($specialisations->isEmpty() && $company->relationLoaded('species')) {
        $specialisations = $company->species->pluck('common_name')->take(4)->values();
    }

    $location = collect([$company->city, $company->region ? $company->region.' Region' : null])
        ->filter()->implode(', ');

    // Only stats with a real backing value are rendered.
    $stats = collect([
        ['label' => 'Products', 'value' => ($company->products_count ?? null) ? $company->products_count : null],
        ['label' => 'Response Rate', 'value' => $company->response_rate_percent !== null ? $company->response_rate_percent.'%' : null],
        ['label' => 'Experience', 'value' => $company->years_experience !== null ? $company->years_experience.' Yrs' : null],
    ])->filter(fn (array $s) => $s['value'] !== null)->values();

    $mobileStats = $stats->take(2);
@endphp

<article class="group relative flex flex-col overflow-hidden rounded-xl border border-sand-300/70 bg-white transition hover:border-forest-200 hover:shadow-lg">

    {{-- Photo header --}}
    <div class="relative">
        <img src="{{ $company->coverUrl() }}" alt=""
             loading="lazy" width="640" height="360"
             class="aspect-[16/9] w-full object-cover">

        @if ($company->is_featured && ! $compact)
            <span class="absolute left-0 top-3 rounded-r-md bg-forest-700 py-1 pl-3 pr-3 text-[0.875rem] font-semibold text-white">Featured</span>
        @endif

        @if ($compact && $verified)
            <span class="absolute right-2 top-2 inline-flex items-center rounded-full bg-forest-700 px-2 py-0.5 text-[0.8125rem] font-semibold text-white">Verified</span>
        @endif

        <button type="button"
                class="absolute z-10 {{ $compact ? 'bottom-2 right-2 h-8 w-8' : 'right-3 top-3 h-8 w-8' }} flex items-center justify-center rounded-full bg-white/95 text-ink-soft shadow-sm transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500"
                aria-label="Save {{ $company->name }} to favourites">
            <x-heroicon-o-heart class="h-4 w-4" />
        </button>

        {{-- Logo badge overlapping the photo --}}
        <img src="{{ $company->logoUrl() }}" alt=""
             loading="lazy" width="136" height="136"
             class="absolute {{ $compact ? '-bottom-4 left-3 h-12 w-12' : '-bottom-6 left-4 h-16 w-16' }} rounded-full bg-white object-cover ring-4 ring-white">
    </div>

    {{-- Body --}}
    <div class="flex flex-1 flex-col {{ $compact ? 'px-3 pb-3 pt-6' : 'px-4 pb-4 pt-8' }}">
        <h3 class="flex flex-wrap items-center gap-x-2 gap-y-1 {{ $compact ? 'text-[1.0625rem]' : 'text-[1rem]' }} font-bold leading-tight text-ink">
            <a href="{{ $profileUrl }}" class="rounded transition after:absolute after:inset-0 hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500">
                {{ $company->name }}
            </a>
            @if ($verified && ! $compact)
                <span class="inline-flex items-center rounded-full bg-forest-700 px-2 py-0.5 text-[0.8125rem] font-semibold text-white">Verified</span>
            @endif
        </h3>

        @if ($location !== '')
            <p class="mt-1.5 flex items-center gap-1 {{ $compact ? 'text-[0.9375rem]' : 'text-[1.0625rem]' }} text-ink-soft">
                <x-heroicon-s-map-pin class="h-3.5 w-3.5 shrink-0 text-forest-600" />
                <span class="truncate">{{ $location }}</span>
            </p>
        @endif

        @if ($specialisations->isNotEmpty())
            <p class="mt-1.5 truncate {{ $compact ? 'text-[0.9375rem]' : 'text-[1.0625rem]' }} text-ink-soft">
                {{ $specialisations->implode(', ') }}
            </p>
        @endif

        @php($shown = $compact ? $mobileStats : $stats)
        @if ($shown->isNotEmpty())
            <dl class="mt-4 grid gap-2 border-y border-sand-200 py-3 text-left"
                style="grid-template-columns: repeat({{ $shown->count() }}, minmax(0, 1fr));">
                @foreach ($shown as $i => $stat)
                    <div @class(['pl-3 border-l border-sand-200' => $i > 0])>
                        <dd class="{{ $compact ? 'text-[1.0625rem]' : 'text-[1.125rem]' }} font-bold text-ink">{{ $stat['value'] }}</dd>
                        <dt class="mt-0.5 text-[0.875rem] text-ink-soft">{{ $stat['label'] }}</dt>
                    </div>
                @endforeach
            </dl>
        @endif

        <div class="mt-auto pt-4">
            @if ($compact)
                <span class="flex w-full items-center justify-center gap-2 rounded-lg bg-forest-50 px-3 py-2.5 text-[1.0625rem] font-semibold text-forest-800">
                    View Profile <x-heroicon-m-arrow-right class="h-4 w-4" />
                </span>
            @else
                <div class="flex gap-2">
                    <span class="flex-1 rounded-lg border border-sand-300 px-3 py-2 text-center text-[1.0625rem] font-semibold text-ink transition group-hover:border-forest-600 group-hover:text-forest-800">
                        View Profile
                    </span>
                    <a href="{{ $profileUrl }}#contact"
                       class="relative z-10 flex-1 rounded-lg bg-forest-700 px-3 py-2 text-center text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                        Contact Supplier
                    </a>
                </div>
            @endif
        </div>
    </div>
</article>
