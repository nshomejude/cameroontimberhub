@props([
    'company',
    'product' => null,
    'topic' => 'general',
    'label' => 'Message Supplier',
    'fallback' => '#contact-supplier',
])

{{--
    In-platform "Message Supplier".

    This is an ADDITION, not a replacement: the WhatsApp / phone affordance
    driven by Company::chatLink() is untouched wherever it already renders, and
    so is the public inquiry form. A signed-in buyer gets an in-platform thread;
    a guest keeps the existing route (the on-page inquiry form), because
    messaging requires an account and we would rather send them to something
    that works than to a login wall from a product page.
--}}
@if (auth()->check())
    <form method="POST" action="{{ route('account.messages.start') }}" class="contents">
        @csrf
        <input type="hidden" name="company" value="{{ $company->slug }}">
        <input type="hidden" name="topic" value="{{ $product ? 'product' : $topic }}">
        @if ($product)
            <input type="hidden" name="product" value="{{ $product->slug }}">
        @endif
        <button type="submit" {{ $attributes }}>
            <x-heroicon-o-chat-bubble-left-right class="h-5 w-5" aria-hidden="true" />
            {{ $label }}
        </button>
    </form>
@else
    <a href="{{ $fallback }}" {{ $attributes }}>
        <x-heroicon-o-envelope class="h-5 w-5" aria-hidden="true" />
        {{ $label }}
    </a>
@endif
