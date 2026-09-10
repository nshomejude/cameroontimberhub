@props([
    'species',
    'compact' => false,
])

@php
    $url = route('species.show', $species->slug);
    $swatch = $species->swatch();

    // Card tags are read straight off the record — commercial category and the
    // EN 350 durability class, shortened for the pill.
    $durabilityTag = preg_match('/\((.+)\)/', (string) $species->durability_class, $m)
        ? $m[1]
        : ($species->durability_class ?: null);

    $tags = collect([$species->commercial_category?->shortLabel(), $durabilityTag])
        ->filter()->take(2)->values();

    $productCount = (int) ($species->products_count ?? 0);
@endphp

<article class="group relative flex flex-col overflow-hidden rounded-xl border border-sand-300/70 bg-white transition hover:border-forest-200 hover:shadow-lg">

    {{-- Grain swatch --}}
    <div class="relative">
        @if ($swatch['image'])
            <img src="{{ $swatch['image'] }}" alt="" loading="lazy" width="480" height="480"
                 class="aspect-square w-full object-cover">
        @else
            {{-- Generated stand-in: the species' own recorded heartwood colour.
                 Decorative only, hence aria-hidden and no alt text. --}}
            <div class="aspect-square w-full"
                 aria-hidden="true"
                 style="background-image:
                        repeating-linear-gradient(97deg, rgba(0,0,0,.10) 0 2px, rgba(255,255,255,.05) 2px 7px, rgba(0,0,0,0) 7px 15px),
                        linear-gradient(160deg, {{ $swatch['from'] }} 0%, {{ $swatch['via'] }} 52%, {{ $swatch['to'] }} 100%);"></div>
        @endif

        <button type="button"
                class="absolute right-2.5 top-2.5 z-10 flex h-8 w-8 items-center justify-center rounded-full bg-white/95 text-ink-soft shadow-sm transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500"
                aria-label="{{ __('messages.common.save_company_favourites', ['name' => $species->common_name]) }}">
            <x-heroicon-o-heart class="h-4 w-4" />
        </button>

        @if ($species->isPremium())
            <span class="absolute bottom-2.5 left-2.5 rounded-md bg-forest-800 px-2 py-1 text-[0.875rem] font-semibold text-white">{{ __('messages.common.premium') }}</span>
        @elseif ($species->is_promoted)
            <span class="absolute bottom-2.5 left-2.5 rounded-md bg-white/95 px-2 py-1 text-[0.875rem] font-semibold text-forest-800">{{ __('messages.common.promoted') }}</span>
        @endif

        @if ($species->is_cites_listed)
            <span class="absolute bottom-2.5 right-2.5 rounded-md bg-timber-700 px-2 py-1 text-[0.875rem] font-semibold text-white">CITES{{ $species->cites_appendix ? ' '.$species->cites_appendix : '' }}</span>
        @endif
    </div>

    {{-- Body --}}
    <div class="flex flex-1 flex-col {{ $compact ? 'px-3 pb-3 pt-3' : 'px-4 pb-4 pt-4' }}">
        <h3 class="{{ $compact ? 'text-[1.125rem]' : 'text-[1.0625rem]' }} font-bold leading-tight text-ink">
            <a href="{{ $url }}" class="rounded transition after:absolute after:inset-0 hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500">
                {{ $species->common_name }}
            </a>
        </h3>

        @if ($species->scientific_name)
            <p class="mt-1 truncate text-[1.0625rem] italic text-ink-soft">{{ $species->scientific_name }}</p>
        @endif

        @if ($tags->isNotEmpty())
            <ul class="mt-2.5 flex flex-wrap gap-1.5">
                @foreach ($tags as $i => $tag)
                    <li @class([
                        'rounded-md px-2 py-1 text-[0.875rem] font-medium',
                        'bg-forest-50 text-forest-800' => $i === 0,
                        'bg-sand-100 text-ink-soft' => $i > 0,
                    ])>{{ $tag }}</li>
                @endforeach
            </ul>
        @endif

        @if ($species->description && ! $compact)
            <p class="mt-3 line-clamp-3 text-[1.0625rem] leading-relaxed text-ink-soft">
                {{ Str::limit(strip_tags($species->description), 130) }}
            </p>
        @endif

        <div class="mt-auto flex items-center gap-2 border-t border-sand-200 pt-3 {{ $compact ? 'mt-3' : 'mt-4' }}">
            <p class="text-[0.9375rem] text-ink-soft">
                @if ($productCount > 0)
                    <span class="font-bold text-ink">{{ $productCount }}</span> {{ $productCount === 1 ? __('messages.species.product') : __('messages.species.products') }}
                @elseif ($species->densityRange())
                    {{ $species->densityRange() }}
                @else
                    {{ __('messages.species.catalogue_entry') }}
                @endif
            </p>
            <span class="ml-auto inline-flex items-center gap-1 text-[0.9375rem] font-semibold text-forest-700">
                {{ __('messages.common.view_details') }} <x-heroicon-m-arrow-right class="h-3.5 w-3.5 transition group-hover:translate-x-0.5" />
            </span>
        </div>
    </div>
</article>
