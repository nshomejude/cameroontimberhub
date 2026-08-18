@props(['product'])

@php
    // Every badge is backed by a real signal; unbacked ones simply do not exist.
    $badges = $product->trustBadges();
@endphp

@if ($badges !== [])
    <section class="mx-4 mt-5 rounded-2xl bg-forest-50 px-2 py-3" aria-labelledby="m-trust-heading">
        <h2 id="m-trust-heading" class="sr-only">Why buy this listing</h2>
        <ul class="grid" role="list" style="grid-template-columns: repeat({{ count($badges) }}, minmax(0, 1fr));">
            @foreach ($badges as $i => $badge)
                <li @class(['flex items-center gap-2 px-2', 'border-l border-forest-200' => $i > 0])
                    data-trust-badge="{{ $badge['key'] }}">
                    <x-dynamic-component :component="'heroicon-o-'.$badge['icon']" class="h-5 w-5 shrink-0 text-forest-700" aria-hidden="true" />
                    <span class="text-[0.8125rem] font-semibold leading-tight text-ink">{{ $badge['label'] }}</span>
                </li>
            @endforeach
        </ul>
    </section>
@endif
