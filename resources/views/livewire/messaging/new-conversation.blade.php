<div>
    <label for="supplier-search" class="sr-only">Search suppliers</label>
    <div class="flex items-center rounded-xl border border-sand-300 bg-white">
        <x-heroicon-m-magnifying-glass class="ml-3 h-4 w-4 shrink-0 text-ink-soft" />
        <input id="supplier-search" type="search" wire:model.live.debounce.400ms="search"
               placeholder="Search supplier or company…"
               class="min-w-0 flex-1 bg-transparent px-3 py-3 text-[0.875rem] outline-none placeholder:text-ink-soft">
    </div>

    {{-- MESSAGE ABOUT — stored on the conversation as `topic`, and used as the
         thread's subject line, so the chip is not decoration. --}}
    <p class="mt-5 text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-ink-soft">Message about</p>
    <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-5">
        @foreach ($topics as $option)
            <button type="button" wire:click="setTopic('{{ $option->value }}')"
                    @class([
                        'flex flex-col items-center gap-2 rounded-xl border px-3 py-4 text-center text-[0.8125rem] font-semibold transition',
                        'border-forest-700 bg-forest-50 text-forest-800' => $topic === $option->value,
                        'border-sand-300 bg-white text-ink hover:bg-sand-50' => $topic !== $option->value,
                    ])>
                <x-dynamic-component :component="'heroicon-o-'.$option->icon()" class="h-6 w-6" />
                {{ $option->label() }}
            </button>
        @endforeach
    </div>

    <p class="mt-3 flex items-start gap-2 rounded-xl bg-forest-50 px-3.5 py-3 text-[0.8125rem] text-forest-900">
        <x-heroicon-o-light-bulb class="mt-0.5 h-4 w-4 shrink-0" />
        Choosing a category helps the supplier route your message to the right person.
    </p>

    @if ($recent->isNotEmpty())
        <p class="mt-6 text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-ink-soft">Recent suppliers</p>
        <ul class="mt-2 divide-y divide-sand-200 overflow-hidden rounded-2xl border border-sand-200 bg-white">
            @foreach ($recent as $company)
                @include('public.account.messages.partials.supplier-row', ['company' => $company, 'topic' => $topic])
            @endforeach
        </ul>
    @endif

    <p class="mt-6 text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-ink-soft">All suppliers</p>
    @if ($companies->isEmpty())
        <p class="mt-2 rounded-2xl border border-dashed border-sand-300 bg-white p-6 text-center text-[0.875rem] text-ink-soft">
            No verified suppliers match that search.
        </p>
    @else
        <ul class="mt-2 divide-y divide-sand-200 overflow-hidden rounded-2xl border border-sand-200 bg-white">
            @foreach ($companies as $company)
                @include('public.account.messages.partials.supplier-row', ['company' => $company, 'topic' => $topic])
            @endforeach
        </ul>
    @endif

    {{-- "Post Requirement" — the real RFQ wizard, not a new dead-end form. --}}
    <div class="mt-6 flex flex-wrap items-center gap-3 rounded-2xl border border-sand-200 bg-forest-50 p-4">
        <div class="min-w-0 flex-1">
            <p class="font-display text-[0.9375rem] font-bold text-forest-950">Can't find the right supplier?</p>
            <p class="text-[0.8125rem] text-ink-soft">Post your requirement and let verified suppliers come to you.</p>
        </div>
        <a href="{{ route('rfq.create') }}"
           class="shrink-0 rounded-xl bg-forest-800 px-4 py-2.5 text-[0.875rem] font-bold text-white transition hover:bg-forest-900">
            Post Requirement
        </a>
    </div>
</div>
