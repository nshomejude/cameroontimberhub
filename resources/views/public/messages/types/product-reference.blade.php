{{-- Product context card (the header card in the thread mockup). --}}
<div class="rounded-2xl border border-sand-200 bg-white p-3 shadow-sm">
    <div class="flex items-center gap-3">
        @if ($image = $message->payloadValue('image_url'))
            <img src="{{ $image }}" alt="" class="h-14 w-20 shrink-0 rounded-xl object-cover">
        @endif
        <div class="min-w-0 flex-1">
            <p class="truncate font-display text-[0.9375rem] font-bold text-forest-950">{{ $message->payloadValue('name') }}</p>
            <p class="truncate text-[0.8125rem] text-ink-soft">
                {{ $conversation->company?->name }}@if ($conversation->company?->region) · {{ $conversation->company->region }}@endif
            </p>
        </div>
        @if ($slug = $message->payloadValue('slug'))
            <a href="{{ route('products.show', $slug) }}"
               class="shrink-0 rounded-xl border border-forest-700 px-3 py-2 text-[0.8125rem] font-bold text-forest-700 transition hover:bg-forest-50">
                View product
            </a>
        @endif
    </div>
</div>
