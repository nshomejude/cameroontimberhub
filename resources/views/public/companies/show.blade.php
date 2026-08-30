@php
    use Illuminate\Support\Str;

    $location = collect([$company->city, $company->region ? $company->region.' Region' : null, 'Cameroon'])
        ->filter()->implode(', ');

    // ---- Chat / quote actions. Rendered only when really available. -------
    $chat = $company->chatLink();
    $quoteUrl = route('rfq.create');
    $marketplaceUrl = route('marketplace', ['supplier' => $company->slug]);

    $capacity = $company->annual_capacity_m3 !== null
        ? number_format((float) $company->annual_capacity_m3).' m³'
        : null;

    $harvest = $company->annual_harvest_capacity_m3 !== null
        ? number_format((float) $company->annual_harvest_capacity_m3).' m³'
        : null;

    // ---- Hero stat strip. A tile appears only with a real value. ---------
    $heroStats = collect([
        ['icon' => 'calendar-days', 'value' => $company->year_founded ? 'Since '.$company->year_founded : null,
         'label' => $company->years_experience ? $company->years_experience.'+ years in service' : 'In service'],
        ['icon' => 'users', 'value' => $company->employee_count ? number_format($company->employee_count).'+' : null, 'label' => 'Employees'],
        ['icon' => 'truck', 'value' => $company->on_time_delivery_percent !== null ? $company->on_time_delivery_percent.'%' : null, 'label' => 'On-time delivery'],
        ['icon' => 'clipboard-document-check', 'value' => $company->orders_completed ? number_format($company->orders_completed).'+' : null, 'label' => 'Orders completed'],
        ['icon' => 'cube', 'value' => $capacity, 'label' => 'Annual capacity'],
    ])->filter(fn ($s) => filled($s['value']))->values();

    // ---- Business summary ------------------------------------------------
    $markets = $company->exportMarkets->pluck('country_code')->filter()->values();

    $summaryRows = collect([
        ['label' => 'Legal name', 'value' => $company->legal_name],
        ['label' => 'Registration number', 'value' => $company->registration_number],
        ['label' => 'Business type', 'value' => $company->supplier_type?->label()],
        ['label' => 'Years in service', 'value' => $company->years_experience ? $company->years_experience.'+ years' : null],
        ['label' => 'Number of employees', 'value' => $company->employee_count ? number_format($company->employee_count) : null],
        ['label' => 'Main markets', 'value' => $markets->isNotEmpty() ? $markets->implode(', ') : null],
        ['label' => 'Annual production capacity', 'value' => $capacity],
        ['label' => 'Payment terms', 'value' => $company->payment_terms],
        ['label' => 'Languages', 'value' => is_array($company->languages) && $company->languages !== [] ? implode(', ', $company->languages) : null],
    ])->filter(fn ($r) => filled($r['value']))->values();

    // ---- Stats panel (real metrics only) ---------------------------------
    $statRows = collect([
        ['icon' => 'star', 'label' => 'Overall rating', 'value' => $company->hasRating() ? rtrim(rtrim(number_format((float) $company->rating_avg, 1), '0'), '.').'/5' : null],
        ['icon' => 'chat-bubble-left-right', 'label' => 'Total reviews', 'value' => $company->hasRating() ? number_format((int) $company->rating_count) : null],
        ['icon' => 'clock', 'label' => 'Response time', 'value' => $company->responseTimeLabel()],
        ['icon' => 'inbox-arrow-down', 'label' => 'Response rate', 'value' => $company->response_rate_percent !== null ? $company->response_rate_percent.'%' : null],
        ['icon' => 'truck', 'label' => 'On-time delivery', 'value' => $company->on_time_delivery_percent !== null ? $company->on_time_delivery_percent.'%' : null],
        ['icon' => 'clipboard-document-check', 'label' => 'Orders completed', 'value' => $company->orders_completed ? number_format($company->orders_completed) : null],
    ])->filter(fn ($r) => filled($r['value']))->values();

    // ---- Forest & sourcing ----------------------------------------------
    $forestRows = collect([
        ['label' => 'Forest location', 'value' => $company->forest_location],
        ['label' => 'Forest management', 'value' => $company->forest_management],
        ['label' => 'Species available', 'value' => $company->species->count() ? $company->species->count().' species' : null],
        ['label' => 'Annual harvest capacity', 'value' => $harvest],
    ])->filter(fn ($r) => filled($r['value']))->values();

    // ---- Logistics & shipping -------------------------------------------
    $logisticsRows = collect([
        ['label' => 'Main ports', 'value' => $company->main_ports],
        ['label' => 'Export markets', 'value' => $markets->isNotEmpty() ? $markets->count().' countries' : null],
        ['label' => 'Average delivery time', 'value' => $company->deliveryTimeLabel()],
        ['label' => 'Shipping terms', 'value' => $company->shipping_terms],
        ['label' => 'Export experience', 'value' => $company->years_experience ? $company->years_experience.'+ years' : null],
    ])->filter(fn ($r) => filled($r['value']))->values();

    // ---- Contact information (company-level + public contacts) -----------
    $contactRows = collect([
        ['icon' => 'envelope', 'label' => 'Email', 'value' => $company->email, 'href' => $company->email ? 'mailto:'.$company->email : null],
        ['icon' => 'phone', 'label' => 'Phone', 'value' => $company->phone, 'href' => $company->phone ? 'tel:'.preg_replace('/[^\d+]/', '', $company->phone) : null],
        ['icon' => 'globe-alt', 'label' => 'Website', 'value' => $company->website_url, 'href' => $company->website_url],
        ['icon' => 'map-pin', 'label' => 'Address', 'value' => collect([$company->address_line, $location])->filter()->implode(', ') ?: null, 'href' => null],
        ['icon' => 'clock', 'label' => 'Working hours', 'value' => $company->working_hours, 'href' => null],
    ])->filter(fn ($r) => filled($r['value']))->values();

    $socialIcons = [
        'linkedin' => 'heroicon-o-briefcase',
        'facebook' => 'heroicon-o-user-group',
        'instagram' => 'heroicon-o-camera',
        'youtube' => 'heroicon-o-play-circle',
        'x' => 'heroicon-o-hashtag',
        'wechat' => 'heroicon-o-chat-bubble-left-right',
        'other' => 'heroicon-o-link',
    ];

    // ---- Mobile fact grid (mockup): only facts with a real value. -------
    $mobileFacts = collect([
        ['icon' => 'trophy', 'label' => 'Years in service', 'value' => $company->years_experience ? $company->years_experience.'+ years' : null],
        ['icon' => 'building-office-2', 'label' => 'Business type', 'value' => $company->supplier_type?->label()],
        ['icon' => 'globe-europe-africa', 'label' => 'Main markets', 'value' => $markets->isNotEmpty() ? $markets->take(4)->implode(', ') : null],
        ['icon' => 'credit-card', 'label' => 'Payment terms', 'value' => $company->payment_terms],
        ['icon' => 'language', 'label' => 'Languages', 'value' => is_array($company->languages) && $company->languages !== [] ? implode(', ', $company->languages) : null],
        ['icon' => 'cube', 'label' => 'Annual capacity', 'value' => $capacity],
        ['icon' => 'clock', 'label' => 'Response time', 'value' => $company->responseTimeLabel()],
        ['icon' => 'truck', 'label' => 'On-time delivery', 'value' => $company->on_time_delivery_percent !== null ? $company->on_time_delivery_percent.'%' : null],
    ])->filter(fn ($f) => filled($f['value']))->values();

    // ---- Tabs. A tab exists only when its panel has real content. --------
    $tabs = collect([
        ['id' => 'overview', 'label' => 'Overview', 'icon' => 'clipboard-document-list', 'show' => true],
        ['id' => 'products', 'label' => 'Products'.($productCount ? ' ('.$productCount.')' : ''), 'icon' => 'squares-2x2', 'show' => $productCount > 0],
        ['id' => 'capacity', 'label' => 'Capacity'.($capacities->count() ? ' ('.$capacities->count().')' : ''), 'icon' => 'chart-bar', 'show' => $capacities->isNotEmpty()],
        ['id' => 'carbon-projects', 'label' => 'Carbon Projects'.($carbonProjects->count() ? ' ('.$carbonProjects->count().')' : ''), 'icon' => 'globe-alt', 'show' => $carbonProjects->isNotEmpty()],
        ['id' => 'certificates', 'label' => 'Certificates'.($badges->count() ? ' ('.$badges->count().')' : ''), 'icon' => 'check-badge', 'show' => $badges->isNotEmpty()],
        ['id' => 'sourcing', 'label' => 'Forest & Sourcing', 'icon' => 'globe-europe-africa', 'show' => $forestRows->isNotEmpty() || $company->species->isNotEmpty()],
        ['id' => 'logistics', 'label' => 'Logistics', 'icon' => 'truck', 'show' => $logisticsRows->isNotEmpty()],
        ['id' => 'documents', 'label' => 'Documents'.($documents->count() ? ' ('.$documents->count().')' : ''), 'icon' => 'document-text', 'show' => $documents->isNotEmpty()],
        ['id' => 'contact', 'label' => 'Contact', 'icon' => 'envelope', 'show' => $contactRows->isNotEmpty() || $company->contacts->isNotEmpty()],
    ])->filter(fn ($t) => $t['show'])->values();

    $firstTab = $tabs->first()['id'] ?? 'overview';
    $tabIds = $tabs->pluck('id')->all();

    // Static class strings: Tailwind cannot see an interpolated utility.
    $heroCols = [1 => 'lg:grid-cols-1', 2 => 'lg:grid-cols-2', 3 => 'lg:grid-cols-3', 4 => 'lg:grid-cols-4', 5 => 'lg:grid-cols-5'][min(5, max(1, $heroStats->count()))];

    $card = 'rounded-xl border border-sand-300/70 bg-white';
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-forest-700 px-5 py-3 text-[1.125rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2';
    $btnGhost = 'inline-flex items-center justify-center gap-2 rounded-lg border border-sand-300 bg-white px-5 py-3 text-[1.125rem] font-semibold text-ink transition hover:border-forest-600 hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2';
@endphp

<x-layouts.app
    :title="$company->meta_title ?: $company->name.' — verified Cameroon timber supplier'"
    :description="$company->meta_description ?: Str::limit(strip_tags((string) $company->description), 160)"
    :image="$company->coverUrl()"
    :breadcrumbs="$breadcrumbs"
    :schema="$schema">

    <div class="bg-sand-50 pb-28 lg:pb-0">

        {{-- ============================================================
             HERO — shared shell, different proportions per breakpoint.
        ============================================================= --}}
        <section class="relative overflow-hidden bg-forest-950">
            <img src="{{ $company->coverUrl() }}" alt="" aria-hidden="true"
                 class="absolute inset-0 h-full w-full object-cover opacity-40">
            <div class="absolute inset-0 bg-gradient-to-r from-forest-950/95 via-forest-950/80 to-forest-900/50"></div>

            <div class="relative mx-auto max-w-[1400px] px-4 py-8 lg:px-6 lg:py-10">
                <nav aria-label="Breadcrumb" class="hidden lg:block">
                    <ol class="flex flex-wrap items-center gap-2 text-[1.0625rem] text-forest-200">
                        @foreach ($breadcrumbs as $i => $crumb)
                            <li>
                                @if ($i === count($breadcrumbs) - 1)
                                    <span aria-current="page" class="font-medium text-white">{{ $crumb['label'] }}</span>
                                @else
                                    <a href="{{ $crumb['url'] }}" class="rounded transition hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">{{ $crumb['label'] }}</a>
                                @endif
                            </li>
                            @if ($i !== count($breadcrumbs) - 1)
                                <li aria-hidden="true" class="text-forest-400">/</li>
                            @endif
                        @endforeach
                    </ol>
                </nav>

                <div class="mt-0 flex flex-col gap-5 lg:mt-6 lg:flex-row lg:items-center lg:gap-8">
                    <img src="{{ $company->logoUrl() }}"
                         alt="{{ $company->name }} logo"
                         width="128" height="128"
                         class="h-24 w-24 shrink-0 rounded-full border-2 border-white/70 bg-white object-contain p-2 lg:h-32 lg:w-32">

                    <div class="min-w-0">
                        @if ($company->hasFeature('verified_badge'))
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-forest-600 px-3 py-1 text-[0.9375rem] font-semibold text-white">
                                <x-heroicon-s-check-badge class="h-4 w-4" aria-hidden="true" />
                                Verified Supplier
                            </span>
                        @endif

                        <h1 class="mt-2 font-display text-[1.75rem] font-semibold leading-tight text-white lg:text-[2.5rem]">
                            {{ $company->name }}
                        </h1>

                        @if ($company->tagline)
                            <p class="mt-1 text-[1rem] text-forest-100">{{ $company->tagline }}</p>
                        @endif

                        @if ($location)
                            <p class="mt-2 flex items-center gap-1.5 text-[1.125rem] text-forest-100">
                                <x-heroicon-s-map-pin class="h-4 w-4 text-timber-300" aria-hidden="true" />
                                {{ $location }}
                            </p>
                        @endif

                        @if ($company->type === \App\Enums\OrganisationType::Artisan)
                            <a href="{{ route('companies.portfolio', $company->slug) }}"
                               class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-white/10 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-white/20">
                                <x-heroicon-m-photo class="h-4 w-4" /> View portfolio
                            </a>
                        @endif
                    </div>
                </div>

                @if ($heroStats->isNotEmpty())
                    <ul class="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-xl bg-white/10 sm:grid-cols-3 lg:mt-8 {{ $heroCols }}">
                        @foreach ($heroStats as $stat)
                            <li class="flex items-center gap-3 bg-forest-950/60 px-4 py-3">
                                <x-dynamic-component :component="'heroicon-o-'.$stat['icon']" class="h-6 w-6 shrink-0 text-timber-300" aria-hidden="true" />
                                <span class="min-w-0">
                                    <span class="block text-[1.0625rem] font-bold text-white">{{ $stat['value'] }}</span>
                                    <span class="block text-[0.9375rem] text-forest-200">{{ $stat['label'] }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($company->species->isNotEmpty())
                    <ul class="mt-4 flex flex-wrap gap-2">
                        @foreach ($company->species->take(8) as $sp)
                            <li>
                                <a href="{{ route('species.show', $sp->slug) }}"
                                   class="inline-block rounded-md bg-white/15 px-3 py-1.5 text-[1.0625rem] font-medium text-white transition hover:bg-white/25 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white">
                                    {{ $sp->common_name }}
                                </a>
                            </li>
                        @endforeach
                        @if ($company->species->count() > 8)
                            <li class="rounded-md bg-white/10 px-3 py-1.5 text-[1.0625rem] text-forest-100">+{{ $company->species->count() - 8 }} more</li>
                        @endif
                    </ul>
                @endif
            </div>
        </section>

        {{-- ============================================================
             ACTION ROW (desktop). The mobile equivalent is the sticky bar.
        ============================================================= --}}
        <div class="mx-auto hidden max-w-[1400px] justify-end gap-3 px-6 py-5 lg:flex">
            @if ($chat)
                <a href="{{ $chat['url'] }}"
                   @if ($chat['channel'] === 'whatsapp') target="_blank" rel="noopener noreferrer" @endif
                   class="{{ $btnGhost }}">
                    <x-dynamic-component :component="$chat['channel'] === 'whatsapp' ? 'heroicon-o-chat-bubble-oval-left' : 'heroicon-o-phone'" class="h-5 w-5" aria-hidden="true" />
                    {{ $chat['label'] }}
                </a>
            @endif
            <x-message-supplier :company="$company" :class="$btnGhost" />
            <a href="{{ $quoteUrl }}" class="{{ $btnPrimary }}">
                <x-heroicon-o-paper-airplane class="h-5 w-5" aria-hidden="true" />
                Request Quote
            </a>
        </div>


        {{-- ============================================================
             MOBILE composition (< lg). The mobile mockup leads with the
             action row, the rating card and an icon fact grid before the
             tabs — a genuinely different arrangement from the desktop page.
        ============================================================= --}}
        <div class="space-y-4 px-4 py-4 lg:hidden">
            <div class="grid gap-2 @if ($chat) grid-cols-1 @endif">
                <a href="#contact-supplier" class="{{ $btnPrimary }} w-full">
                    <x-heroicon-o-chat-bubble-left-right class="h-5 w-5" aria-hidden="true" />
                    Message Supplier
                </a>
            </div>

            @if ($company->hasRating())
                <section class="{{ $card }} p-4">
                    <h2 class="text-[1.125rem] font-bold text-ink">Overall rating</h2>
                    <div class="mt-2 flex items-center gap-3">
                        <span class="font-display text-[2rem] font-semibold leading-none text-forest-800">{{ rtrim(rtrim(number_format((float) $company->rating_avg, 1), '0'), '.') }}</span>
                        <span class="text-[1.0625rem] text-ink-soft">/ 5</span>
                        <x-star-rating :rating="$company->rating_avg" :count="$company->rating_count" />
                    </div>
                </section>
            @endif

            @if ($mobileFacts->isNotEmpty())
                <section class="{{ $card }} p-4">
                    <h2 class="sr-only">Supplier facts</h2>
                    <ul class="grid grid-cols-2 gap-4">
                        @foreach ($mobileFacts as $fact)
                            <li class="text-center">
                                <x-dynamic-component :component="'heroicon-o-'.$fact['icon']" class="mx-auto h-6 w-6 text-forest-600" aria-hidden="true" />
                                <p class="mt-1.5 text-[0.9375rem] text-ink-soft">{{ $fact['label'] }}</p>
                                <p class="text-[1.0625rem] font-semibold text-ink">{{ $fact['value'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        {{-- ============================================================
             TABS + PANELS. Every panel is rendered server-side so crawlers
             and answer engines see the full profile without running JS.
        ============================================================= --}}
        <div class="mx-auto max-w-[1400px] px-4 pb-10 lg:px-6"
             x-data="{
                tab: '{{ $firstTab }}',
                tabs: @js($tabIds),
                move(step) {
                    const i = this.tabs.indexOf(this.tab);
                    const next = this.tabs[(i + step + this.tabs.length) % this.tabs.length];
                    this.tab = next;
                    this.$refs['tab-' + next].focus();
                },
             }">

            <div class="mt-4 overflow-x-auto rounded-t-xl border border-b-0 border-sand-300/70 bg-white lg:mt-0">
                <div role="tablist" aria-label="Supplier profile sections" class="flex min-w-max px-2">
                    @foreach ($tabs as $t)
                        <button type="button"
                                role="tab"
                                x-ref="tab-{{ $t['id'] }}"
                                id="tab-{{ $t['id'] }}"
                                aria-controls="panel-{{ $t['id'] }}"
                                :aria-selected="tab === '{{ $t['id'] }}' ? 'true' : 'false'"
                                aria-selected="{{ $t['id'] === $firstTab ? 'true' : 'false' }}"
                                :tabindex="tab === '{{ $t['id'] }}' ? 0 : -1"
                                @click="tab = '{{ $t['id'] }}'"
                                @keydown.right.prevent="move(1)"
                                @keydown.left.prevent="move(-1)"
                                @keydown.home.prevent="tab = tabs[0]; $refs['tab-' + tabs[0]].focus()"
                                @keydown.end.prevent="tab = tabs[tabs.length - 1]; $refs['tab-' + tabs[tabs.length - 1]].focus()"
                                :class="tab === '{{ $t['id'] }}' ? 'border-forest-700 text-forest-800' : 'border-transparent text-ink-soft hover:text-ink'"
                                class="flex items-center gap-2 border-b-2 px-4 py-3.5 text-[1.0625rem] font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500">
                            <x-dynamic-component :component="'heroicon-o-'.$t['icon']" class="h-4 w-4" aria-hidden="true" />
                            {{ $t['label'] }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="rounded-b-xl border border-sand-300/70 bg-sand-50 p-4 lg:p-6">
                @foreach ($tabs as $t)
                    <div role="tabpanel"
                         id="panel-{{ $t['id'] }}"
                         aria-labelledby="tab-{{ $t['id'] }}"
                         tabindex="0"
                         x-show="tab === '{{ $t['id'] }}'"
                         @if ($t['id'] !== $firstTab) x-cloak @endif
                         class="focus-visible:outline-none">

                        @switch($t['id'])

                            {{-- ---------------- Overview ---------------- --}}
                            @case('overview')
                                <div class="grid gap-4 lg:grid-cols-3">
                                    <section class="{{ $card }} p-5 lg:col-span-2">
                                        <h2 class="text-[1.0625rem] font-bold text-ink">About {{ $company->name }}</h2>
                                        <div class="mt-3 space-y-3 text-[1.125rem] leading-relaxed text-ink-soft">
                                            @foreach (preg_split('/\n{2,}/', (string) $company->description) as $para)
                                                @if (trim($para) !== '')
                                                    <p>{{ trim($para) }}</p>
                                                @endif
                                            @endforeach
                                        </div>

                                        @if ($summaryRows->isNotEmpty())
                                            <h3 class="mt-6 text-[1.125rem] font-bold text-ink">Business summary</h3>
                                            <dl class="mt-3 divide-y divide-sand-200 border-t border-sand-200">
                                                @foreach ($summaryRows as $row)
                                                    <div class="flex flex-wrap justify-between gap-2 py-2.5 text-[1.0625rem]">
                                                        <dt class="text-ink-soft">{{ $row['label'] }}</dt>
                                                        <dd class="font-medium text-ink">{{ $row['value'] }}</dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        @endif
                                    </section>

                                    <div class="space-y-4">
                                        @if ($statRows->isNotEmpty())
                                            <section class="{{ $card }} p-5">
                                                <h2 class="text-[1.0625rem] font-bold text-ink">Supplier stats</h2>
                                                @if ($company->hasRating())
                                                    <div class="mt-3 flex items-center gap-3 rounded-lg bg-sand-100 p-3">
                                                        <span class="font-display text-[2rem] font-semibold leading-none text-forest-800">{{ rtrim(rtrim(number_format((float) $company->rating_avg, 1), '0'), '.') }}</span>
                                                        <x-star-rating :rating="$company->rating_avg" :count="$company->rating_count" />
                                                    </div>
                                                @endif
                                                <dl class="mt-3 divide-y divide-sand-200 border-t border-sand-200">
                                                    @foreach ($statRows as $row)
                                                        <div class="flex items-center justify-between gap-3 py-2.5 text-[1.0625rem]">
                                                            <dt class="flex items-center gap-2 text-ink-soft">
                                                                <x-dynamic-component :component="'heroicon-o-'.$row['icon']" class="h-4 w-4 text-forest-600" aria-hidden="true" />
                                                                {{ $row['label'] }}
                                                            </dt>
                                                            <dd class="font-semibold text-ink">{{ $row['value'] }}</dd>
                                                        </div>
                                                    @endforeach
                                                </dl>
                                            </section>
                                        @endif

                                        @if ($categories->isNotEmpty())
                                            <section class="{{ $card }} p-5">
                                                <h2 class="text-[1.0625rem] font-bold text-ink">Product categories</h2>
                                                <ul class="mt-3 divide-y divide-sand-200 border-t border-sand-200">
                                                    @foreach ($categories as $cat)
                                                        <li>
                                                            <a href="{{ $cat['url'] }}" class="flex items-center justify-between gap-3 py-2.5 text-[1.0625rem] text-ink transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">
                                                                <span>{{ $cat['label'] }}</span>
                                                                <span class="font-semibold text-ink-soft">{{ $cat['count'] }}</span>
                                                            </a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </section>
                                        @endif

                                        {{-- Verified badge is a plan entitlement (verified_badge): gate the
                                             display, not the underlying verification record. --}}
                                        @if ($company->hasFeature('verified_badge'))
                                            <section class="{{ $card }} p-5">
                                                <h2 class="flex items-center gap-2 text-[1.0625rem] font-bold text-ink">
                                                    <x-heroicon-s-shield-check class="h-5 w-5 text-forest-600" aria-hidden="true" />
                                                    Verification
                                                </h2>
                                                <dl class="mt-3 space-y-2 text-[1.0625rem]">
                                                    <div class="flex justify-between gap-3"><dt class="text-ink-soft">Status</dt><dd class="font-semibold text-forest-700">Verified profile</dd></div>
                                                    @if ($badge?->issued_at)
                                                        <div class="flex justify-between gap-3"><dt class="text-ink-soft">Verification date</dt><dd class="text-ink">{{ $badge->issued_at->format('d M Y') }}</dd></div>
                                                    @endif
                                                    @if ($badge?->valid_until)
                                                        <div class="flex justify-between gap-3"><dt class="text-ink-soft">Valid until</dt><dd class="text-ink">{{ $badge->valid_until->format('d M Y') }}</dd></div>
                                                    @endif
                                                    @if ($badge?->reference_code)
                                                        <div class="flex justify-between gap-3"><dt class="text-ink-soft">Reference</dt><dd class="text-ink">{{ $badge->reference_code }}</dd></div>
                                                    @endif
                                                </dl>
                                                <p class="mt-4 border-t border-sand-200 pt-3 text-[0.9375rem] leading-relaxed text-ink-soft">
                                                    Documents reviewed by Cameroon Timber Hub based on information submitted by the company.
                                                    Buyers should conduct final due diligence before any transaction.
                                                </p>
                                            </section>
                                        @endif

                                        {{--
                                            Buyer reviews.

                                            Phase 3 added the `company_reviews`
                                            table; before it, this platform had
                                            `rating_avg` / `rating_count` columns
                                            with nothing behind them and no
                                            review content anywhere, which
                                            earlier phases deliberately refused
                                            to fabricate.

                                            Every row here is a real review left
                                            by a real buyer against a completed
                                            order they owned, one per order,
                                            enforced by a unique index. The
                                            section is absent entirely when
                                            there are none — no "Be the first to
                                            review" shell, no empty star strip.

                                            Bodies are escaped by Blade. This is
                                            the one place on a public page where
                                            buyer-authored free text is shown to
                                            strangers, so nothing here uses
                                            {!! !!} and nothing un-escapes to
                                            "render formatting".
                                        --}}
                                        @if ($reviews->isNotEmpty())
                                            <section class="{{ $card }} p-5">
                                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                                    <h2 class="text-[1.0625rem] font-bold text-ink">Buyer reviews</h2>
                                                    <span class="text-[1.0625rem] text-ink-soft">{{ number_format($reviewCount) }} total</span>
                                                </div>

                                                @if ($company->hasRating())
                                                    <div class="mt-3 flex items-center gap-3 rounded-lg bg-sand-100 p-3">
                                                        <span class="font-display text-[2rem] font-semibold leading-none text-forest-800">{{ rtrim(rtrim(number_format((float) $company->rating_avg, 1), '0'), '.') }}</span>
                                                        <x-star-rating :rating="$company->rating_avg" :count="$company->rating_count" />
                                                    </div>
                                                @endif

                                                <ul class="mt-3 divide-y divide-sand-200 border-t border-sand-200">
                                                    @foreach ($reviews as $review)
                                                        <li class="py-3">
                                                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                                                <span class="text-[1.0625rem] font-semibold text-ink">{{ $review->rating }}/5</span>
                                                                <span class="text-[0.9375rem] text-ink-soft">{{ $review->created_at->format('d M Y') }}</span>
                                                            </div>
                                                            @if ($review->title)
                                                                <p class="mt-1 text-[1.0625rem] font-semibold text-ink">{{ $review->title }}</p>
                                                            @endif
                                                            @if ($review->body)
                                                                <p class="mt-1 whitespace-pre-line text-[1.0625rem] leading-relaxed text-ink-soft">{{ $review->body }}</p>
                                                            @endif
                                                            <p class="mt-1.5 text-[0.9375rem] text-ink-soft">
                                                                {{ $review->authorDisplayName() }} · verified order
                                                            </p>
                                                        </li>
                                                    @endforeach
                                                </ul>

                                                <p class="mt-3 border-t border-sand-200 pt-3 text-[0.9375rem] leading-relaxed text-ink-soft">
                                                    Reviews can only be left by a buyer who completed an order with this supplier
                                                    on Cameroon Timber Hub, and each order can be reviewed once.
                                                </p>
                                            </section>
                                        @endif
                                    </div>
                                </div>
                                @break

                            {{-- ---------------- Products ---------------- --}}
                            @case('products')
                                <section>
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <h2 class="text-[1.0625rem] font-bold text-ink">Products from {{ $company->name }}</h2>
                                        <a href="{{ $marketplaceUrl }}" class="inline-flex items-center gap-1 rounded text-[1.0625rem] font-semibold text-forest-700 transition hover:text-forest-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">
                                            View all {{ $productCount }} products
                                            <x-heroicon-m-arrow-right class="h-4 w-4" aria-hidden="true" />
                                        </a>
                                    </div>
                                    <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
                                        @foreach ($products as $product)
                                            <x-product-card :product="$product" />
                                        @endforeach
                                    </div>
                                </section>
                                @break

                            {{-- ---------------- Capacity ---------------- --}}
                            @case('capacity')
                                <section>
                                    <h2 class="text-[1.0625rem] font-bold text-ink">Capacity from {{ $company->name }}</h2>
                                    <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
                                        @foreach ($capacities as $item)
                                            <div class="{{ $card }} p-4">
                                                <p class="font-semibold text-ink">{{ $item->capability }}</p>
                                                <p class="mt-1 text-[1.0625rem] text-ink-soft">
                                                    {{ number_format((float) $item->quantity) }} {{ $item->unit }}@if ($item->period) / {{ $item->period }}@endif
                                                </p>
                                            </div>
                                        @endforeach
                                    </div>
                                </section>
                                @break

                            {{-- ---------------- Carbon Projects ---------------- --}}
                            @case('carbon-projects')
                                <section>
                                    <h2 class="text-[1.0625rem] font-bold text-ink">Carbon projects from {{ $company->name }}</h2>
                                    <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
                                        @foreach ($carbonProjects as $project)
                                            <a href="{{ route('carbon-projects.show', $project) }}" class="{{ $card }} block p-4 transition hover:border-forest-200 hover:shadow-lg">
                                                <div class="flex items-start justify-between gap-2">
                                                    <p class="font-semibold text-ink">{{ $project->name }}</p>
                                                    @if ($project->project_type)
                                                        <span class="shrink-0 rounded-full bg-forest-100 px-2 py-0.5 text-[0.8125rem] font-medium text-forest-800">{{ $project->project_type }}</span>
                                                    @endif
                                                </div>
                                                @if ($project->region)
                                                    <p class="mt-1 text-[0.9375rem] text-ink-soft">{{ $project->region }}</p>
                                                @endif
                                                @if ($project->area_hectares)
                                                    <p class="mt-1 text-[0.9375rem] text-ink-soft">{{ number_format((float) $project->area_hectares) }} ha</p>
                                                @endif
                                                @if ($project->description)
                                                    <p class="mt-2 text-[0.9375rem] leading-relaxed text-ink-soft">{{ Str::limit(strip_tags($project->description), 120) }}</p>
                                                @endif
                                            </a>
                                        @endforeach
                                    </div>
                                </section>
                                @break

                            {{-- ---------------- Certificates ---------------- --}}
                            @case('certificates')
                                <section class="{{ $card }} p-5">
                                    <h2 class="text-[1.0625rem] font-bold text-ink">{{ $badges->count() }} active {{ Str::plural('certification', $badges->count()) }}</h2>
                                    <p class="mt-1 text-[1.0625rem] text-ink-soft">Issued and reviewed by Cameroon Timber Hub. Only active, unexpired credentials are listed.</p>
                                    <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                        @foreach ($badges as $activeBadge)
                                            <li class="rounded-lg border border-sand-300/70 p-4 text-center">
                                                <x-heroicon-s-check-badge class="mx-auto h-8 w-8 text-forest-600" aria-hidden="true" />
                                                <p class="mt-2 text-[1.0625rem] font-semibold text-ink">{{ $activeBadge->badge_type?->label() }}</p>
                                                @if ($activeBadge->reference_code)
                                                    <p class="mt-1 text-[0.9375rem] text-ink-soft">{{ $activeBadge->reference_code }}</p>
                                                @endif
                                                @if ($activeBadge->valid_until)
                                                    <p class="mt-1 text-[0.9375rem] text-ink-soft">Valid until {{ $activeBadge->valid_until->format('d M Y') }}</p>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </section>
                                @break

                            {{-- ---------------- Forest & sourcing ---------------- --}}
                            @case('sourcing')
                                <div class="grid gap-4 lg:grid-cols-2">
                                    @if ($forestRows->isNotEmpty())
                                        <section class="{{ $card }} p-5">
                                            <h2 class="text-[1.0625rem] font-bold text-ink">Forest &amp; sourcing</h2>
                                            <dl class="mt-3 divide-y divide-sand-200 border-t border-sand-200">
                                                @foreach ($forestRows as $row)
                                                    <div class="flex flex-wrap justify-between gap-2 py-2.5 text-[1.0625rem]">
                                                        <dt class="text-ink-soft">{{ $row['label'] }}</dt>
                                                        <dd class="font-medium text-ink">{{ $row['value'] }}</dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        </section>
                                    @endif

                                    @if ($company->species->isNotEmpty())
                                        <section class="{{ $card }} p-5">
                                            <h2 class="text-[1.0625rem] font-bold text-ink">Species handled</h2>
                                            <ul class="mt-3 flex flex-wrap gap-2">
                                                @foreach ($company->species as $sp)
                                                    <li>
                                                        <a href="{{ route('species.show', $sp->slug) }}"
                                                           class="inline-flex items-center gap-1.5 rounded-full border border-sand-300 px-3 py-1.5 text-[1.0625rem] font-medium text-forest-800 transition hover:border-forest-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">
                                                            {{ $sp->common_name }}
                                                            <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 text-timber-500" aria-hidden="true" />
                                                        </a>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </section>
                                    @endif
                                </div>
                                @break

                            {{-- ---------------- Logistics ---------------- --}}
                            @case('logistics')
                                <div class="grid gap-4 lg:grid-cols-2">
                                    <section class="{{ $card }} p-5">
                                        <h2 class="text-[1.0625rem] font-bold text-ink">Logistics &amp; shipping</h2>
                                        <dl class="mt-3 divide-y divide-sand-200 border-t border-sand-200">
                                            @foreach ($logisticsRows as $row)
                                                <div class="flex flex-wrap justify-between gap-2 py-2.5 text-[1.0625rem]">
                                                    <dt class="text-ink-soft">{{ $row['label'] }}</dt>
                                                    <dd class="font-medium text-ink">{{ $row['value'] }}</dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    </section>

                                    @if ($markets->isNotEmpty())
                                        <section class="{{ $card }} p-5">
                                            <h2 class="text-[1.0625rem] font-bold text-ink">Export markets</h2>
                                            <ul class="mt-3 flex flex-wrap gap-2">
                                                @foreach ($markets as $code)
                                                    <li class="rounded-md bg-sand-100 px-3 py-1.5 text-[1.0625rem] font-medium text-ink-soft">{{ $code }}</li>
                                                @endforeach
                                            </ul>
                                        </section>
                                    @endif
                                </div>
                                @break

                            {{-- ---------------- Documents ---------------- --}}
                            @case('documents')
                                <section class="{{ $card }} p-5">
                                    <h2 class="text-[1.0625rem] font-bold text-ink">Public documents</h2>
                                    <p class="mt-1 text-[1.0625rem] text-ink-soft">Downloads are issued through a signed, authenticated link. Private and buyer-only files are never listed here.</p>
                                    <ul class="mt-4 divide-y divide-sand-200 border-t border-sand-200">
                                        @foreach ($documents as $document)
                                            <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-[1.0625rem]">
                                                <span class="flex items-center gap-2 font-medium text-ink">
                                                    <x-heroicon-o-document-text class="h-5 w-5 text-forest-600" aria-hidden="true" />
                                                    {{ $document->documentType?->name ?? $document->original_filename }}
                                                </span>
                                                @if ($document->expiry_date)
                                                    <span class="text-ink-soft">Valid until {{ $document->expiry_date->format('d M Y') }}</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </section>
                                @break

                            {{-- ---------------- Contact ---------------- --}}
                            @case('contact')
                                <div class="grid gap-4 lg:grid-cols-2">
                                    <section class="{{ $card }} p-5">
                                        <h2 class="text-[1.0625rem] font-bold text-ink">Contact information</h2>
                                        <dl class="mt-3 space-y-3 text-[1.0625rem]">
                                            @foreach ($contactRows as $row)
                                                <div class="flex items-start gap-3">
                                                    <x-dynamic-component :component="'heroicon-o-'.$row['icon']" class="mt-0.5 h-5 w-5 shrink-0 text-forest-600" aria-hidden="true" />
                                                    <div class="min-w-0">
                                                        <dt class="text-ink-soft">{{ $row['label'] }}</dt>
                                                        <dd class="break-words font-medium text-ink">
                                                            @if ($row['href'])
                                                                <a href="{{ $row['href'] }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">{{ $row['value'] }}</a>
                                                            @else
                                                                {{ $row['value'] }}
                                                            @endif
                                                        </dd>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </dl>

                                        @if ($company->contacts->isNotEmpty())
                                            <h3 class="mt-5 text-[1.125rem] font-bold text-ink">Trade desk</h3>
                                            <ul class="mt-2 space-y-3 text-[1.0625rem]">
                                                @foreach ($company->contacts as $contact)
                                                    <li>
                                                        <p class="font-medium text-ink">{{ $contact->name }}@if ($contact->title)<span class="font-normal text-ink-soft"> · {{ $contact->title }}</span>@endif</p>
                                                        @if ($contact->email)<p class="mt-0.5 text-ink-soft">{{ $contact->email }}</p>@endif
                                                        @if ($contact->phone)<p class="mt-0.5 text-ink-soft">{{ $contact->phone }}</p>@endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif

                                        @if ($company->socialLinks->isNotEmpty())
                                            <ul class="mt-5 flex flex-wrap gap-2">
                                                @foreach ($company->socialLinks as $link)
                                                    <li>
                                                        <a href="{{ $link->url }}" target="_blank" rel="noopener noreferrer"
                                                           class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-forest-700 text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500"
                                                           aria-label="{{ $company->name }} on {{ Str::headline($link->platform) }}">
                                                            <x-dynamic-component :component="$socialIcons[$link->platform] ?? 'heroicon-o-link'" class="h-4 w-4" aria-hidden="true" />
                                                        </a>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </section>

                                    {{-- Inquiry form: the real, throttled, double-opt-in channel. --}}
                                    <section id="contact-supplier" class="{{ $card }} p-5">
                                        <h2 class="text-[1.0625rem] font-bold text-ink">Work with {{ $company->name }}</h2>
                                        @if (session('inquiry_sent'))
                                            <p role="status" class="mt-3 rounded-lg bg-forest-50 p-3 text-[1.0625rem] text-forest-800">Thanks — check your email to confirm and deliver your message.</p>
                                        @else
                                            @php($f = 'w-full rounded-lg border border-sand-300 bg-white px-3 py-2.5 text-[1.0625rem] text-ink focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-100')
                                            <form method="POST" action="{{ route('inquiry.store', $company->slug) }}" class="mt-4 space-y-3">
                                                @csrf
                                                <input type="hidden" name="form_rendered_at" value="{{ now()->timestamp }}">
                                                <div class="hidden" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>

                                                <div>
                                                    <label for="inq-name" class="block text-[1.0625rem] font-medium text-ink">Your name</label>
                                                    <input id="inq-name" type="text" name="name" value="{{ old('name') }}" class="{{ $f }} mt-1" required>
                                                </div>
                                                <div>
                                                    <label for="inq-email" class="block text-[1.0625rem] font-medium text-ink">Email</label>
                                                    <input id="inq-email" type="email" name="email" value="{{ old('email') }}" class="{{ $f }} mt-1" required>
                                                </div>
                                                <div>
                                                    <label for="inq-phone" class="block text-[1.0625rem] font-medium text-ink">Phone <span class="font-normal text-ink-soft">(optional)</span></label>
                                                    <input id="inq-phone" type="text" name="phone" value="{{ old('phone') }}" class="{{ $f }} mt-1">
                                                </div>
                                                <div>
                                                    <label for="inq-message" class="block text-[1.0625rem] font-medium text-ink">Your message</label>
                                                    <textarea id="inq-message" name="message" rows="4" class="{{ $f }} mt-1" required>{{ old('message') }}</textarea>
                                                    <p class="mt-1 text-[0.9375rem] text-ink-soft">Minimum 20 characters.</p>
                                                </div>
                                                <label class="flex items-start gap-2 text-[0.9375rem] text-ink-soft">
                                                    <input type="checkbox" name="consent" value="1" class="mt-0.5 rounded border-sand-300" required>
                                                    <span>I consent to be contacted by email about this inquiry.</span>
                                                </label>
                                                <button type="submit" class="{{ $btnPrimary }} w-full">
                                                    <x-heroicon-o-paper-airplane class="h-5 w-5" aria-hidden="true" />
                                                    Send inquiry
                                                </button>
                                            </form>
                                        @endif
                                        <a href="{{ $quoteUrl }}" class="mt-3 inline-block rounded text-[1.0625rem] font-medium text-forest-700 transition hover:text-forest-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">
                                            Or request a multi-supplier quote &rarr;
                                        </a>
                                    </section>
                                </div>
                                @break

                        @endswitch
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ============================================================
             MOBILE sticky action bar. Sits directly above the app's bottom
             tab bar (4.5rem) and respects the safe area, mirroring
             product-mobile/actions — the page reserves matching padding.
        ============================================================= --}}
        <div class="fixed inset-x-0 z-30 border-t border-sand-200 bg-white px-4 py-3 lg:hidden"
             style="bottom: calc(4.5rem + env(safe-area-inset-bottom))">
            <div class="flex gap-3">
                <a href="{{ $quoteUrl }}"
                   @class([
                       'flex items-center justify-center gap-2 rounded-lg border border-forest-700 px-4 py-3 text-[1.125rem] font-semibold text-forest-800 transition hover:bg-forest-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2',
                       'flex-1' => $chat !== null,
                       'w-full' => $chat === null,
                   ])>
                    <x-heroicon-o-paper-airplane class="h-5 w-5" aria-hidden="true" />
                    Request Quote
                </a>

                @if ($chat)
                    <a href="{{ $chat['url'] }}"
                       @if ($chat['channel'] === 'whatsapp') target="_blank" rel="noopener noreferrer" @endif
                       class="flex flex-1 items-center justify-center gap-2 rounded-lg bg-forest-700 px-4 py-3 text-[1.125rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                        <x-dynamic-component :component="$chat['channel'] === 'whatsapp' ? 'heroicon-o-chat-bubble-oval-left' : 'heroicon-o-phone'" class="h-5 w-5" aria-hidden="true" />
                        {{ $chat['label'] }}
                    </a>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
