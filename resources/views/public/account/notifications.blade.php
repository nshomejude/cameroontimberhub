<x-layouts.account
    :title="__('messages.account_center.notifications_title')"
    :heading="__('messages.account_center.notifications_title')"
    :subheading="__('messages.account_center.notifications_subheading')">

    <x-account.flash />

    @if ($notifications->total() === 0)
        <x-account.blank icon="bell"
            :title="__('messages.account_center.notifications_empty_title')"
            :body="__('messages.account_center.notifications_empty_body')" />
    @else
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <p class="text-[1.0625rem] text-ink-soft">
                {{ __('messages.account_center.notifications_unread', ['count' => number_format($unread)]) }}
            </p>
            @if ($unread > 0)
                <form method="POST" action="{{ route('account.notifications.read-all') }}" class="ml-auto">
                    @csrf
                    <button type="submit"
                            class="inline-flex items-center gap-1.5 rounded-full border border-forest-700 px-4 py-2 text-[1.0625rem] font-semibold text-forest-700 transition hover:bg-forest-50">
                        <x-heroicon-m-check class="h-4 w-4" /> {{ __('messages.account_center.mark_all_read') }}
                    </button>
                </form>
            @endif
        </div>

        <ul class="space-y-3">
            @foreach ($rows as $row)
                <li @class([
                        'rounded-2xl border bg-white p-4 lg:p-5',
                        'border-forest-300' => $row['read_at'] === null,
                        'border-sand-200' => $row['read_at'] !== null,
                    ])>
                    <div class="flex items-start gap-3">
                        <span @class([
                                'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl',
                                'bg-amber-50 text-amber-700' => $row['tone'] === 'warning',
                                'bg-forest-50 text-forest-700' => $row['tone'] !== 'warning',
                            ])>
                            <x-dynamic-component :component="'heroicon-o-'.$row['icon']" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-display text-[1.0625rem] font-bold text-forest-950">{{ $row['title'] ?? __('messages.account_center.notifications_title') }}</p>
                                @if ($row['read_at'] === null)
                                    <x-account.status-pill :label="__('messages.account_center.notification_new')" color="success" />
                                @endif
                            </div>
                            @if ($row['body'])
                                <p class="mt-1 text-[1.0625rem] text-ink">{{ $row['body'] }}</p>
                            @endif
                            <p class="mt-1 text-[0.9375rem] text-ink-soft">
                                {{ $row['created_at'] ? \Illuminate\Support\Carbon::parse($row['created_at'])->diffForHumans() : '' }}
                                @if ($row['reference']) · {{ $row['reference'] }} @endif
                            </p>
                        </div>
                        @if ($row['read_at'] === null)
                            <form method="POST" action="{{ route('account.notifications.read', $row['id']) }}" class="shrink-0">
                                @csrf
                                <button type="submit" class="text-[1.0625rem] font-semibold text-forest-700 transition hover:text-forest-900">
                                    {{ __('messages.account_center.mark_read') }}
                                </button>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-5"><x-account.pagination :paginator="$notifications" :noun="__('messages.account_center.noun_notifications')" /></div>
    @endif
</x-layouts.account>
