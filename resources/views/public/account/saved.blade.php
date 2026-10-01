<x-layouts.account
    :title="__('messages.account_center.saved_title')"
    :heading="__('messages.account_center.saved_title')"
    :subheading="__('messages.account_center.saved_subheading')">

    <x-account.flash />

    @if ($favorites->total() === 0)
        <x-account.blank icon="bookmark"
            :title="__('messages.account_center.saved_empty_title')"
            :body="__('messages.account_center.saved_empty_body')"
            :cta-label="__('messages.account_center.saved_browse')" :cta-url="route('directory')" />
    @else
        <ul class="grid gap-3 md:grid-cols-2">
            @foreach ($favorites as $favorite)
                @php($company = $favorite->favoritable)
                @continue($company === null)
                @php($visible = in_array($company->getKey(), $visibleIds, true))
                <li class="flex items-center gap-3 rounded-2xl border border-sand-200 bg-white p-4 lg:p-5">
                    <img src="{{ $company->logoUrl() }}" alt="" class="h-12 w-12 shrink-0 rounded-xl border border-sand-200 object-cover">
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-display text-[1.0625rem] font-bold text-forest-950">{{ $company->name }}</p>
                        <p class="truncate text-[0.9375rem] text-ink-soft">
                            {{ collect([$company->city, $company->region])->filter()->implode(', ') }}
                        </p>
                        @unless ($visible)
                            <x-account.status-pill class="mt-1" :label="__('messages.account_center.saved_unavailable')" color="gray" />
                        @endunless
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2">
                        @if ($visible)
                            <a href="{{ route('companies.show', $company->slug) }}"
                               class="inline-flex items-center gap-1.5 rounded-full bg-forest-700 px-4 py-2 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800">
                                {{ __('messages.account_center.saved_view') }}
                            </a>
                        @endif
                        <form method="POST" action="{{ route('account.saved.destroy', $company->slug) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-[1.0625rem] font-semibold text-red-700 transition hover:text-red-900">
                                {{ __('messages.account_center.saved_remove') }}
                            </button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-5"><x-account.pagination :paginator="$favorites" :noun="__('messages.account_center.noun_suppliers')" /></div>
    @endif
</x-layouts.account>
