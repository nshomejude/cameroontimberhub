<x-layouts.account
    title="Quotes"
    heading="Quotes"
    subheading="Every response suppliers have sent to your requests.">

    @if ($quotes->total() === 0)
        <x-account.blank icon="tag"
            title="No quotes received yet"
            body="Suppliers routed to your requests respond here. Post a request and the first responses usually arrive within a few working days."
            cta-label="Post an RFQ" :cta-url="route('rfq.create')" />
    @else
        <p class="mb-3 text-[1.0625rem] text-ink-soft">
            {{ number_format($quotes->total()) }} {{ Str::plural('quote', $quotes->total()) }}
        </p>

        {{-- Desktop table --}}
        <div class="hidden overflow-x-auto rounded-2xl border border-sand-200 bg-white lg:block">
            <table class="w-full min-w-[52rem] text-left text-[1.0625rem]">
                <thead>
                    <tr class="border-b border-sand-200 text-[0.875rem] uppercase tracking-wide text-ink-soft">
                        <th scope="col" class="py-3 pl-5 pr-3 font-semibold">Quote</th>
                        <th scope="col" class="py-3 pr-3 font-semibold">Supplier</th>
                        <th scope="col" class="py-3 pr-3 font-semibold">Request</th>
                        <th scope="col" class="py-3 pr-3 font-semibold">Total</th>
                        <th scope="col" class="py-3 pr-3 font-semibold">Lead time</th>
                        <th scope="col" class="py-3 pr-3 font-semibold">Valid until</th>
                        <th scope="col" class="py-3 pr-5 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sand-200">
                    @foreach ($quotes as $quote)
                        <tr class="transition hover:bg-sand-50">
                            <td class="py-3 pl-5 pr-3">
                                <a href="{{ $access->link(request(), 'quote', $quote->rfq, $quote) }}"
                                   class="font-semibold text-forest-700 transition hover:text-forest-900">{{ $quote->reference_code }}</a>
                            </td>
                            <td class="py-3 pr-3 text-ink">{{ $quote->company?->name ?? '—' }}</td>
                            <td class="py-3 pr-3">
                                <a href="{{ $access->link(request(), 'responses', $quote->rfq) }}"
                                   class="text-ink-soft transition hover:text-forest-700">{{ $quote->rfq?->reference_code }}</a>
                            </td>
                            <td class="py-3 pr-3 font-semibold text-ink">{{ $quote->money($quote->total_amount) }}</td>
                            <td class="py-3 pr-3 text-ink-soft">{{ $quote->lead_time_days ? $quote->lead_time_days.' days' : '—' }}</td>
                            <td class="py-3 pr-3 text-ink-soft">{{ $quote->valid_until?->isoFormat('D MMM YYYY') ?? '—' }}</td>
                            <td class="py-3 pr-5">
                                <x-account.status-pill
                                    :label="$quote->isExpired() ? 'Expired' : $quote->status->label()"
                                    :color="$quote->isExpired() ? 'danger' : $quote->status->color()" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Mobile cards --}}
        <ul class="space-y-3 lg:hidden">
            @foreach ($quotes as $quote)
                <li>
                    <a href="{{ $access->link(request(), 'quote', $quote->rfq, $quote) }}"
                       class="block rounded-2xl border border-sand-200 bg-white p-4">
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[1.125rem] font-semibold text-ink">{{ $quote->company?->name ?? 'Supplier' }}</p>
                                <p class="truncate text-[1.0625rem] text-ink-soft">{{ $quote->reference_code }} · {{ $quote->rfq?->reference_code }}</p>
                            </div>
                            <x-account.status-pill
                                :label="$quote->isExpired() ? 'Expired' : $quote->status->label()"
                                :color="$quote->isExpired() ? 'danger' : $quote->status->color()" />
                        </div>
                        <div class="mt-3 flex items-end justify-between gap-3 border-t border-sand-200 pt-3">
                            <span class="font-display text-lg font-bold text-forest-800">{{ $quote->money($quote->total_amount) }}</span>
                            <span class="text-[0.9375rem] text-ink-soft">
                                @if ($quote->lead_time_days) {{ $quote->lead_time_days }} days lead @endif
                                @if ($quote->valid_until) · valid to {{ $quote->valid_until->isoFormat('D MMM') }} @endif
                            </span>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-5"><x-account.pagination :paginator="$quotes" noun="quotes" /></div>
    @endif
</x-layouts.account>
