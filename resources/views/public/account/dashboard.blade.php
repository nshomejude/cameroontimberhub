@php
    use App\Services\BuyerDashboard;

    // Donut geometry. Segments are drawn as stroke dash offsets on one circle,
    // so no charting library is involved and every arc length is a real share.
    $donutR = 56;
    $donutC = 2 * M_PI * $donutR;

    // Segment colours, keyed by the enum's own colour name so a status reads
    // the same here as it does on the order screens.
    $segmentColor = [
        'success' => '#15703d',
        'info' => '#1f7d45',
        'warning' => '#c38851',
        'danger' => '#8a5a30',
        'gray' => '#c2c2b8',
    ];
@endphp

<x-layouts.account
    title="Dashboard"
    :heading="'Dashboard'"
    :subheading="'Welcome back, '.$user->name">

    {{-- ====================== Headline stats ====================== --}}
    <section aria-label="Overview" class="grid grid-cols-2 gap-3 lg:grid-cols-5 lg:gap-4">
        @foreach ($stats as $stat)
            <x-account.stat-card :stat="$stat" />
        @endforeach
    </section>

    <div class="mt-4 grid gap-4 lg:mt-6 lg:grid-cols-3 lg:gap-6">

        {{-- ====================== Left column ====================== --}}
        <div class="space-y-4 lg:col-span-2 lg:space-y-6">

            {{-- ---------- Awarded order value ---------- --}}
            @if ($trend)
                <x-account.panel title="Awarded order value" subtitle="Value of orders you awarded, by month. Awarded value is not a payment record.">
                    <p class="font-display text-2xl font-bold text-forest-950 lg:text-[1.75rem]">
                        {{ BuyerDashboard::money($trend['currency'], $trend['total']) }}
                    </p>
                    <p class="text-[0.8125rem] text-ink-soft">Last 12 months ({{ $trend['currency'] }})</p>

                    @php
                        $max = max($trend['max'], 1);
                        $n = count($trend['points']) - 1;
                        $coords = collect($trend['points'])->map(fn ($p, $i) => [
                            'x' => round(($i / max($n, 1)) * 600, 2),
                            'y' => round(200 - ($p['value'] / $max) * 176, 2),
                            'point' => $p,
                        ]);
                        $line = $coords->map(fn ($c) => $c['x'].','.$c['y'])->implode(' ');
                    @endphp

                    <figure class="mt-4">
                        <figcaption class="sr-only">Awarded order value per month, in {{ $trend['currency'] }}.</figcaption>
                        <div class="flex gap-3">
                            <div class="flex flex-col justify-between py-0.5 text-[0.625rem] text-ink-soft">
                                <span>{{ BuyerDashboard::compact($trend['currency'], $trend['max']) }}</span>
                                <span>{{ BuyerDashboard::compact($trend['currency'], $trend['max'] / 2) }}</span>
                                <span>0</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <svg viewBox="0 0 600 200" preserveAspectRatio="none" role="img"
                                     class="h-40 w-full lg:h-52" aria-label="Monthly awarded order value">
                                    <defs>
                                        <linearGradient id="trendFill" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0%" stop-color="#15703d" stop-opacity="0.28" />
                                            <stop offset="100%" stop-color="#15703d" stop-opacity="0" />
                                        </linearGradient>
                                    </defs>
                                    @foreach ([24, 112, 200] as $gy)
                                        <line x1="0" y1="{{ $gy }}" x2="600" y2="{{ $gy }}" stroke="#e0e0da" stroke-width="1" vector-effect="non-scaling-stroke" />
                                    @endforeach
                                    <polygon points="0,200 {{ $line }} 600,200" fill="url(#trendFill)" />
                                    <polyline points="{{ $line }}" fill="none" stroke="#15703d" stroke-width="2.5"
                                              stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                    @foreach ($coords as $c)
                                        <circle cx="{{ $c['x'] }}" cy="{{ $c['y'] }}" r="3.5" fill="#0a5223" vector-effect="non-scaling-stroke">
                                            <title>{{ $c['point']['month'] }} — {{ BuyerDashboard::money($trend['currency'], $c['point']['value']) }}</title>
                                        </circle>
                                    @endforeach
                                </svg>
                                <div class="mt-1 flex justify-between text-[0.625rem] text-ink-soft">
                                    @foreach ($trend['points'] as $i => $point)
                                        <span @class(['hidden sm:inline' => $i % 2 === 1])>{{ $point['label'] }}</span>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </figure>
                </x-account.panel>
            @elseif ($awardedValue)
                <x-account.panel title="Awarded order value" subtitle="Value of orders you awarded this year. Awarded value is not a payment record.">
                    <dl class="flex flex-wrap gap-x-10 gap-y-4">
                        @foreach ($awardedValue as $row)
                            <div>
                                <dt class="text-[0.6875rem] uppercase tracking-wide text-ink-soft">{{ $row['currency'] }} this year</dt>
                                <dd class="mt-1 font-display text-2xl font-bold text-forest-950">{{ BuyerDashboard::money($row['currency'], $row['total']) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-account.panel>
            @endif

            {{-- ---------- Recent orders ---------- --}}
            <x-account.panel title="Recent orders" :href="route('account.orders')" :padded="false">
                @if ($recentOrders->isEmpty())
                    <div class="px-5 pb-5">
                        <x-account.blank icon="clipboard-document-check"
                            title="No orders yet"
                            body="Once you award a quote, the resulting order appears here with its own reference and receipt."
                            cta-label="Review your quotes" :cta-url="route('account.quotes')" />
                    </div>
                @else
                    {{-- Desktop table --}}
                    <div class="hidden overflow-x-auto lg:block">
                        <table class="w-full min-w-[46rem] text-left text-[0.875rem]">
                            <thead>
                                <tr class="border-y border-sand-200 text-[0.6875rem] uppercase tracking-wide text-ink-soft">
                                    <th scope="col" class="py-2.5 pl-5 pr-3 font-semibold">Order</th>
                                    <th scope="col" class="py-2.5 pr-3 font-semibold">Supplier</th>
                                    <th scope="col" class="py-2.5 pr-3 font-semibold">Items</th>
                                    <th scope="col" class="py-2.5 pr-3 font-semibold">Order value</th>
                                    <th scope="col" class="py-2.5 pr-3 font-semibold">Status</th>
                                    <th scope="col" class="py-2.5 pr-5 font-semibold">Awarded</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-sand-200">
                                @foreach ($recentOrders as $order)
                                    <tr class="transition hover:bg-sand-50">
                                        <td class="py-3 pl-5 pr-3">
                                            <a href="{{ $access->link(request(), 'order', $order->rfq) }}"
                                               class="font-semibold text-forest-700 transition hover:text-forest-900">{{ $order->reference_code }}</a>
                                        </td>
                                        <td class="py-3 pr-3 text-ink">{{ $order->supplier_name }}</td>
                                        <td class="py-3 pr-3 text-ink-soft">{{ $order->items->count() }} {{ Str::plural('line', $order->items->count()) }}</td>
                                        <td class="py-3 pr-3 font-semibold text-ink">{{ $order->money($order->total_amount) }}</td>
                                        <td class="py-3 pr-3">
                                            <x-account.status-pill :label="$order->status->label()" :color="$order->status->color()" />
                                        </td>
                                        <td class="py-3 pr-5 text-ink-soft">{{ $order->awarded_at?->isoFormat('D MMM YYYY') ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Mobile rows --}}
                    <ul class="divide-y divide-sand-200 border-t border-sand-200 lg:hidden">
                        @foreach ($recentOrders as $order)
                            <li>
                                <a href="{{ $access->link(request(), 'order', $order->rfq) }}" class="flex items-center gap-3 px-5 py-3.5">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-[0.875rem] font-semibold text-forest-700">{{ $order->reference_code }}</span>
                                        <span class="block truncate text-[0.8125rem] text-ink-soft">{{ $order->supplier_name }}</span>
                                    </span>
                                    <x-account.status-pill :label="$order->status->label()" :color="$order->status->color()" />
                                    <span class="shrink-0 text-[0.8125rem] font-semibold text-ink">{{ $order->money($order->total_amount) }}</span>
                                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-ink-soft" />
                                </a>
                            </li>
                        @endforeach
                    </ul>

                    <div class="border-t border-sand-200 p-4 text-center">
                        <a href="{{ route('account.orders') }}"
                           class="inline-flex items-center gap-2 rounded-full border border-sand-300 px-5 py-2 text-[0.8125rem] font-semibold text-ink transition hover:border-forest-400">
                            View all orders
                        </a>
                    </div>
                @endif
            </x-account.panel>

            {{-- ---------- Latest quotes ---------- --}}
            <x-account.panel title="Latest quotes received" :href="route('account.quotes')" :padded="false">
                @if ($recentQuotes->isEmpty())
                    <div class="px-5 pb-5">
                        <x-account.blank icon="tag"
                            title="No quotes yet"
                            body="Suppliers routed to your requests will respond here. You will be emailed as soon as the first quote lands."
                            cta-label="See your requests" :cta-url="route('account.rfqs')" />
                    </div>
                @else
                    <ul class="divide-y divide-sand-200 border-t border-sand-200">
                        @foreach ($recentQuotes as $quote)
                            <li>
                                <a href="{{ $access->link(request(), 'quote', $quote->rfq, $quote) }}"
                                   class="flex items-center gap-3 px-5 py-3.5 transition hover:bg-sand-50">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-[0.875rem] font-semibold text-ink">{{ $quote->company?->name ?? 'Supplier' }}</span>
                                        <span class="block truncate text-[0.8125rem] text-ink-soft">
                                            {{ $quote->reference_code }} · {{ $quote->rfq?->reference_code }}
                                        </span>
                                    </span>
                                    <x-account.status-pill :label="$quote->status->label()" :color="$quote->status->color()" class="hidden sm:inline-flex" />
                                    <span class="shrink-0 text-right">
                                        <span class="block text-[0.875rem] font-semibold text-ink">{{ $quote->money($quote->total_amount) }}</span>
                                        <span class="block text-[0.75rem] text-ink-soft">{{ $quote->submitted_at?->diffForHumans() }}</span>
                                    </span>
                                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-ink-soft" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-account.panel>
        </div>

        {{-- ====================== Right rail ====================== --}}
        <div class="space-y-4 lg:space-y-6">

            {{-- ---------- Orders by status ---------- --}}
            @if ($byStatus)
                <x-account.panel title="Orders by status">
                    <div class="flex flex-wrap items-center gap-6">
                        @php $offset = 0; @endphp
                        <svg viewBox="0 0 160 160" role="img" class="h-40 w-40 shrink-0 -rotate-90"
                             aria-label="{{ $byStatus['total'] }} orders, split by status">
                            @foreach ($byStatus['slices'] as $slice)
                                @php
                                    $len = $donutC * $slice['count'] / $byStatus['total'];
                                    $dash = round($len, 3).' '.round($donutC - $len, 3);
                                    $thisOffset = round(-$offset, 3);
                                    $offset += $len;
                                @endphp
                                <circle cx="80" cy="80" r="{{ $donutR }}" fill="none" stroke-width="22"
                                        stroke="{{ $segmentColor[$slice['status']->color()] ?? '#c2c2b8' }}"
                                        stroke-dasharray="{{ $dash }}" stroke-dashoffset="{{ $thisOffset }}">
                                    <title>{{ $slice['status']->label() }}: {{ $slice['count'] }}</title>
                                </circle>
                            @endforeach
                        </svg>
                        <div class="min-w-0 flex-1">
                            <p class="font-display text-2xl font-bold text-forest-950">{{ $byStatus['total'] }}</p>
                            <p class="text-[0.8125rem] text-ink-soft">Total orders</p>
                            <ul class="mt-3 space-y-1.5">
                                @foreach ($byStatus['slices'] as $slice)
                                    <li class="flex items-center gap-2 text-[0.8125rem]">
                                        <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $segmentColor[$slice['status']->color()] ?? '#c2c2b8' }}"></span>
                                        <span class="min-w-0 flex-1 truncate text-ink">{{ $slice['status']->label() }}</span>
                                        <span class="shrink-0 font-semibold text-ink-soft">{{ $slice['count'] }} ({{ rtrim(rtrim(number_format($slice['percent'], 1), '0'), '.') }}%)</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </x-account.panel>
            @endif

            {{-- ---------- Recent activity ---------- --}}
            <x-account.panel title="Recent activity" subtitle="Derived from real timestamps on your quotes and orders.">
                @if (empty($activity))
                    <p class="text-[0.875rem] text-ink-soft">Nothing has happened on your requests yet.</p>
                @else
                    <ul class="space-y-4">
                        @foreach ($activity as $entry)
                            <li class="flex gap-3">
                                <span @class([
                                    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full',
                                    'bg-forest-50 text-forest-700' => $entry['tone'] === 'forest',
                                    'bg-timber-50 text-timber-700' => $entry['tone'] !== 'forest',
                                ])>
                                    <x-dynamic-component :component="'heroicon-o-'.$entry['icon']" class="h-5 w-5" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    @if ($entry['url'])
                                        <a href="{{ $entry['url'] }}" class="block text-[0.875rem] font-semibold text-ink transition hover:text-forest-700">{{ $entry['title'] }}</a>
                                    @else
                                        <span class="block text-[0.875rem] font-semibold text-ink">{{ $entry['title'] }}</span>
                                    @endif
                                    <span class="block truncate text-[0.8125rem] text-ink-soft">{{ $entry['detail'] }}</span>
                                </span>
                                <span class="shrink-0 text-[0.75rem] text-ink-soft">{{ $entry['at']->diffForHumans(short: true) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-account.panel>

            {{-- ---------- Suppliers ---------- --}}
            @if ($topSuppliers->isNotEmpty())
                <x-account.panel title="Your suppliers" subtitle="Ranked by orders you have awarded them." :href="route('directory')" link-label="Browse all">
                    <ul class="space-y-3.5">
                        @foreach ($topSuppliers as $row)
                            @continue (! $row['company'])
                            <li class="flex items-center gap-3">
                                <img src="{{ $row['company']->logoUrl() }}" alt="" class="h-9 w-9 shrink-0 rounded-full object-cover">
                                <span class="min-w-0 flex-1">
                                    <a href="{{ route('companies.show', $row['company']->slug) }}"
                                       class="block truncate text-[0.875rem] font-semibold text-ink transition hover:text-forest-700">{{ $row['company']->name }}</a>
                                    <span class="block text-[0.75rem] text-ink-soft">{{ $row['orders'] }} {{ Str::plural('order', $row['orders']) }} awarded</span>
                                </span>
                                {{-- Rating is a real company column, shown only when it is backed by ratings. --}}
                                @if ($row['company']->rating_avg && $row['company']->rating_count)
                                    <span class="flex shrink-0 items-center gap-1 text-[0.8125rem] font-semibold text-ink">
                                        {{ number_format((float) $row['company']->rating_avg, 1) }}
                                        <x-heroicon-s-star class="h-4 w-4 text-timber-400" />
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-account.panel>
            @endif

            {{-- ---------- Quick actions ---------- --}}
            <x-account.panel title="Quick actions">
                <x-account.quick-actions />
            </x-account.panel>
        </div>
    </div>
</x-layouts.account>
