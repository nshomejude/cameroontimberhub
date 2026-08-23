@props(['species'])

@php
    $url = route('species.show', $species->slug);
    $swatch = $species->swatch();
    $productCount = (int) ($species->products_count ?? 0);

    // List view surfaces the technical record that the grid card has no room
    // for — all of it read straight off the species row.
    $facts = collect([
        ['label' => 'Density', 'value' => $species->densityRange()],
        ['label' => 'Durability', 'value' => $species->durability_class],
        ['label' => 'Janka', 'value' => $species->janka_hardness ? number_format($species->janka_hardness).' N' : null],
        ['label' => 'Family', 'value' => $species->family],
    ])->filter(fn (array $f): bool => filled($f['value']))->values();

    $uses = collect($species->typical_uses ?? [])->take(4);
@endphp

{{-- List view: a genuinely different layout — landscape swatch, the technical
     record as an inline definition list, and the typical-use chips inline. --}}
<article class="relative flex flex-col gap-5 rounded-xl border border-sand-300/70 bg-white p-4 transition hover:border-forest-200 hover:shadow-md sm:flex-row">

    <div class="relative w-full shrink-0 sm:w-56">
        @if ($swatch['image'])
            <img src="{{ $swatch['image'] }}" alt="" loading="lazy" width="480" height="300"
                 class="aspect-[16/10] w-full rounded-lg object-cover">
        @else
            <div class="aspect-[16/10] w-full rounded-lg" aria-hidden="true"
                 style="background-image:
                        repeating-linear-gradient(97deg, rgba(0,0,0,.10) 0 2px, rgba(255,255,255,.05) 2px 7px, rgba(0,0,0,0) 7px 15px),
                        linear-gradient(160deg, {{ $swatch['from'] }} 0%, {{ $swatch['via'] }} 52%, {{ $swatch['to'] }} 100%);"></div>
        @endif

        @if ($species->isPremium())
            <span class="absolute bottom-2 left-2 rounded-md bg-forest-800 px-2 py-1 text-[0.875rem] font-semibold text-white">Premium</span>
        @elseif ($species->is_promoted)
            <span class="absolute bottom-2 left-2 rounded-md bg-white/95 px-2 py-1 text-[0.875rem] font-semibold text-forest-800">Promoted</span>
        @endif
    </div>

    <div class="min-w-0 flex-1">
        <div class="flex items-start gap-3">
            <div class="min-w-0 flex-1">
                <h3 class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[1.0625rem] font-bold leading-tight text-ink">
                    <a href="{{ $url }}" class="rounded transition after:absolute after:inset-0 hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500">
                        {{ $species->common_name }}
                    </a>
                    @if ($species->commercial_category)
                        <span class="inline-flex items-center rounded-full bg-forest-50 px-2 py-0.5 text-[0.8125rem] font-semibold text-forest-800">{{ $species->commercial_category->shortLabel() }}</span>
                    @endif
                    @if ($species->is_cites_listed)
                        <span class="inline-flex items-center rounded-full bg-timber-100 px-2 py-0.5 text-[0.8125rem] font-semibold text-timber-800">CITES{{ $species->cites_appendix ? ' '.$species->cites_appendix : '' }}</span>
                    @endif
                </h3>
                @if ($species->scientific_name)
                    <p class="mt-1 text-[1.0625rem] italic text-ink-soft">{{ $species->scientific_name }}</p>
                @endif
            </div>

            <button type="button"
                    class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-sand-300 text-ink-soft transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500"
                    aria-label="Save {{ $species->common_name }} to favourites">
                <x-heroicon-o-heart class="h-4 w-4" />
            </button>
        </div>

        @if ($species->description)
            <p class="mt-2 line-clamp-2 text-[1.0625rem] leading-relaxed text-ink-soft">{{ strip_tags($species->description) }}</p>
        @endif

        @if ($facts->isNotEmpty())
            <dl class="mt-3 flex flex-wrap items-baseline gap-x-6 gap-y-1">
                @foreach ($facts as $fact)
                    <div class="flex items-baseline gap-1.5">
                        <dt class="text-[0.875rem] uppercase tracking-wide text-ink-soft">{{ $fact['label'] }}</dt>
                        <dd class="text-[1.0625rem] font-semibold text-ink">{{ $fact['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if ($uses->isNotEmpty())
            <ul class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($uses as $use)
                    <li class="rounded-full bg-sand-100 px-2.5 py-1 text-[0.875rem] font-medium text-ink-soft">{{ $use }}</li>
                @endforeach
            </ul>
        @endif

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <p class="text-[1.0625rem] text-ink-soft">
                @if ($productCount > 0)
                    <span class="font-bold text-ink">{{ $productCount }}</span> {{ Str::plural('Product', $productCount) }} listed
                @else
                    No listings yet
                @endif
            </p>
            <a href="{{ $url }}"
               class="relative z-10 ml-auto rounded-lg bg-forest-700 px-4 py-2 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                View Details
            </a>
        </div>
    </div>
</article>
