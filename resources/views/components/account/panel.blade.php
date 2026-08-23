@props([
    'title' => null,
    'subtitle' => null,
    'href' => null,
    'linkLabel' => 'View all',
    'padded' => true,
])

<section {{ $attributes->class('rounded-2xl border border-sand-200 bg-white') }}>
    @if ($title)
        <div class="flex items-center gap-3 px-5 pt-5 {{ $padded ? '' : 'pb-4' }}">
            <div class="min-w-0">
                <h2 class="font-display text-[1.0625rem] font-bold text-forest-950">{{ $title }}</h2>
                @if ($subtitle)
                    <p class="mt-0.5 text-[1.0625rem] text-ink-soft">{{ $subtitle }}</p>
                @endif
            </div>
            @if ($href)
                <a href="{{ $href }}" class="ml-auto shrink-0 text-[1.0625rem] font-semibold text-forest-700 transition hover:text-forest-900">{{ $linkLabel }}</a>
            @endif
        </div>
    @endif

    <div class="{{ $padded ? 'p-5' : '' }}">
        {{ $slot }}
    </div>
</section>
