{{-- One supplier on the New Conversation screen. The Message button is a real
     POST form, so it works with or without JavaScript. --}}
<li class="flex items-center gap-3 px-4 py-3">
    <img src="{{ $company->logoUrl() }}" alt="" class="h-11 w-11 shrink-0 rounded-full object-cover">

    <div class="min-w-0 flex-1">
        <p class="flex items-center gap-1.5 truncate text-[1.125rem] font-semibold text-forest-950">
            {{ $company->name }}
            @if ($company->verified_at)
                <x-heroicon-s-check-badge class="h-4 w-4 shrink-0 text-forest-600" />
            @endif
        </p>
        <p class="truncate text-[1.0625rem] text-ink-soft">
            @if ($company->verified_at) {{ __('messages.account.verified_supplier') }} @endif
            @if ($company->region) · {{ $company->region }} @endif
        </p>
        @if ($company->relationLoaded('species') && $company->species->isNotEmpty())
            <p class="truncate text-[1.0625rem] text-ink-soft">
                {{ $company->species->take(3)->pluck('common_name')->join(' · ') }}
            </p>
        @endif
    </div>

    <form method="POST" action="{{ route('account.messages.start') }}" class="shrink-0">
        @csrf
        <input type="hidden" name="company" value="{{ $company->slug }}">
        <input type="hidden" name="topic" value="{{ $topic }}">
        <button type="submit"
                class="flex items-center gap-1.5 rounded-xl bg-forest-50 px-3.5 py-2 text-[1.0625rem] font-bold text-forest-800 transition hover:bg-forest-100">
            <x-heroicon-o-chat-bubble-left-right class="h-4 w-4" />
            {{ __('messages.account.message') }}
        </button>
    </form>
</li>
