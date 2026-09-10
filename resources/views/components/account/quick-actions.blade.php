@php
    // Four real destinations, matching the mockup's quick-action grid. The
    // mockup's "Track Shipments" tile has no backing feature, so it is replaced
    // by receipt verification, which does exist.
    $actions = [
        ['label' => __('messages.account.qa_post_rfq'), 'icon' => 'document-plus', 'url' => route('rfq.create')],
        ['label' => __('messages.account.qa_browse_timber'), 'icon' => 'cube', 'url' => url('/marketplace')],
        ['label' => __('messages.account.qa_find_suppliers'), 'icon' => 'user-group', 'url' => route('directory')],
        ['label' => __('messages.account.qa_verify_receipt'), 'icon' => 'shield-check', 'url' => route('receipts.verify')],
    ];
@endphp

<div {{ $attributes->class('grid grid-cols-4 gap-2.5') }}>
    @foreach ($actions as $action)
        <a href="{{ $action['url'] }}"
           class="flex flex-col items-center gap-2 rounded-xl border border-sand-200 px-1 py-3.5 text-center transition hover:border-forest-400 hover:bg-forest-50">
            <x-dynamic-component :component="'heroicon-o-'.$action['icon']" class="h-6 w-6 text-forest-700" />
            <span class="text-[0.875rem] font-semibold leading-tight text-ink">{{ $action['label'] }}</span>
        </a>
    @endforeach
</div>
