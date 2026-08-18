@php
    use Illuminate\Support\Str;

    $company = $product->company;
    $species = $product->species;
    $verifiedSupplier = $company?->status === \App\Enums\CompanyStatus::Verified;

    $images = $product->galleryImages();
    $hasGallery = count($images) > 1;

    $specRows = $product->specificationRows();

    // The icon list beside the hero. Only rows with a real backing value.
    $heroSpecs = collect([
        ['icon' => 'globe-alt', 'label' => 'Species', 'value' => $species?->common_name
            ? $species->common_name.($species->scientific_name ? ' ('.$species->scientific_name.')' : '')
            : null, 'url' => $species ? route('species.show', $species->slug) : null],
        ['icon' => 'squares-2x2', 'label' => 'Product Type', 'value' => $product->product_type->label()],
        ['icon' => 'arrows-up-down', 'label' => 'Thickness', 'value' => $product->thickness_mm !== null ? rtrim(rtrim(number_format((float) $product->thickness_mm, 2), '0'), '.').'mm' : null],
        ['icon' => 'arrows-right-left', 'label' => 'Width', 'value' => $product->widthLabel()],
        ['icon' => 'arrow-long-right', 'label' => 'Length', 'value' => $product->lengthLabel()],
        ['icon' => 'beaker', 'label' => 'Moisture Content', 'value' => $product->moisture_content],
        ['icon' => 'check-badge', 'label' => 'Grade', 'value' => $product->grade],
        ['icon' => 'map-pin', 'label' => 'Origin', 'value' => $product->origin],
        ['icon' => 'shield-check', 'label' => 'Certification', 'value' => $product->certification, 'accent' => true],
        ['icon' => 'cube', 'label' => 'Minimum Order', 'value' => $product->moqLabel()],
    ])->filter(fn ($row) => filled($row['value']))->values();

    // Supplier stat strip — a tile appears only when its value is real.
    $supplierStats = collect([
        ['label' => 'Products', 'value' => $supplierProductCount > 0 ? $supplierProductCount : null],
        ['label' => 'Orders Completed', 'value' => $company?->orders_completed],
        ['label' => 'Response Time', 'value' => $company?->responseTimeLabel()],
        ['label' => 'Experience', 'value' => $company?->years_experience ? $company->years_experience.' yrs' : null],
    ])->filter(fn ($s) => filled($s['value']))->take(3)->values();

    $markets = $company?->relationLoaded('exportMarkets')
        ? $company->exportMarkets->pluck('country_code')->filter()->take(8)->implode(', ')
        : null;

    $certificates = $company?->relationLoaded('activeBadges')
        ? $company->activeBadges->map(fn ($b) => $b->badge_type?->label())->filter()->unique()->implode(', ')
        : null;

    $supplierMeta = collect([
        ['icon' => 'calendar-days', 'label' => 'Member Since', 'value' => $company?->memberSince()],
        ['icon' => 'building-office-2', 'label' => 'Business Type', 'value' => $company?->supplier_type?->label()],
        ['icon' => 'globe-europe-africa', 'label' => 'Main Markets', 'value' => $markets ?: null],
        ['icon' => 'document-check', 'label' => 'Certificates', 'value' => $certificates ?: null],
        ['icon' => 'language', 'label' => 'Languages', 'value' => is_array($company?->languages) ? implode(', ', $company->languages) : null],
    ])->filter(fn ($row) => filled($row['value']))->values();

    $benefits = collect(is_array($product->key_benefits) ? $product->key_benefits : [])->filter()->values();

    // Tabs. A panel is only offered when it has real content behind it.
    $tabs = collect([
        ['id' => 'description', 'label' => 'Description', 'show' => filled($product->description) || $benefits->isNotEmpty()],
        ['id' => 'details', 'label' => 'Product Details', 'show' => $specRows !== []],
        ['id' => 'shipping', 'label' => 'Shipping & Delivery', 'show' => true],
        ['id' => 'certifications', 'label' => 'Certifications', 'show' => filled($product->certification) || filled($certificates)],
        ['id' => 'reviews', 'label' => $product->hasRating() ? 'Reviews ('.$product->reviews_count.')' : 'Reviews', 'show' => true],
    ])->filter(fn ($t) => $t['show'])->values();

    $firstTab = $tabs->first()['id'] ?? 'description';

    $btnPrimary = 'flex w-full items-center justify-center gap-2 rounded-lg bg-forest-700 px-5 py-3.5 text-[0.9375rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2';
    $btnGhost = 'flex w-full items-center justify-center gap-2 rounded-lg border border-sand-300 px-5 py-3 text-[0.9375rem] font-semibold text-ink transition hover:border-forest-600 hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2';
    $card = 'rounded-xl border border-sand-300/70 bg-white';
@endphp

<x-layouts.app
    :title="$product->meta_title ?: $product->name"
    :description="$product->meta_description ?: Str::limit(strip_tags((string) $product->description), 160)"
    :image="$product->primaryImageUrl()"
    :breadcrumbs="$breadcrumbs"
    :schema="$schema">

    <div class="bg-white pb-28 lg:pb-0">

        {{-- ============================================================
             MOBILE layout (< lg). A genuinely different composition from the
             desktop page: dark hero band, spec chips, price block, supplier
             card, trust strip and a compact details table. Everything below is
             rendered server-side.
        ============================================================= --}}
        <div class="lg:hidden">
            @if (session('status'))
                <p role="status" class="mx-4 mt-4 rounded-lg border border-forest-200 bg-forest-50 px-4 py-3 text-[0.875rem] font-medium text-forest-800">
                    {{ session('status') }}
                </p>
            @endif

            <x-product-mobile.gallery :product="$product" :breadcrumbs="$breadcrumbs" :in-rfq-list="$inRfqList" />
            <x-product-mobile.summary :product="$product" />
            <x-product-mobile.price :product="$product" />
            @if ($company)
                <x-product-mobile.supplier :company="$company" :product-count="$supplierProductCount" />
            @endif
            <x-product-mobile.trust :product="$product" />
            <x-product-mobile.details :product="$product" />
        </div>

        {{-- ============================================================
             DESKTOP layout (lg and up) — approved and shipped; unchanged.
        ============================================================= --}}
        <div class="hidden lg:block">
        <div class="mx-auto max-w-[1400px] px-4 py-4 lg:px-6 lg:py-6">

            {{-- ---------------- Breadcrumb ---------------- --}}
            <nav aria-label="Breadcrumb">
                <ol class="flex flex-wrap items-center gap-2 text-[0.75rem] text-ink-soft lg:text-[0.8125rem]">
                    @foreach ($breadcrumbs as $i => $crumb)
                        <li>
                            @if ($i === count($breadcrumbs) - 1)
                                <span aria-current="page" class="font-medium text-ink">{{ $crumb['label'] }}</span>
                            @else
                                <a href="{{ $crumb['url'] }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">{{ $crumb['label'] }}</a>
                            @endif
                        </li>
                        @if ($i !== count($breadcrumbs) - 1)
                            <li aria-hidden="true">/</li>
                        @endif
                    @endforeach
                </ol>
            </nav>

            @if (session('status'))
                <p role="status" class="mt-4 rounded-lg border border-forest-200 bg-forest-50 px-4 py-3 text-[0.875rem] font-medium text-forest-800">
                    {{ session('status') }}
                </p>
            @endif

            {{-- ============================================================
                 HERO — gallery + buying panel
            ============================================================= --}}
            <div class="mt-4 grid gap-6 lg:grid-cols-2 lg:gap-8">

                {{-- ---------------- Gallery ---------------- --}}
                <div x-data="{
                        active: 0,
                        total: {{ max(count($images), 1) }},
                        go(i) { this.active = (i + this.total) % this.total; },
                     }">
                    <div class="relative overflow-hidden rounded-xl bg-sand-100">
                        @forelse ($images as $i => $image)
                            <img src="{{ $image['url'] }}"
                                 alt="{{ $i === 0 ? $product->name : ($image['alt'] ?: '') }}"
                                 width="960" height="720"
                                 @if ($i > 0) x-show="active === {{ $i }}" x-cloak @else x-show="active === 0" @endif
                                 class="aspect-[4/3] w-full object-cover">
                        @empty
                            <div class="flex aspect-[4/3] w-full items-center justify-center text-sand-400" aria-hidden="true">
                                <x-heroicon-o-photo class="h-14 w-14" />
                            </div>
                        @endforelse

                        @if ($product->is_best_seller)
                            <span class="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded-md bg-forest-800 px-3 py-1.5 text-[0.75rem] font-semibold text-white">
                                <x-heroicon-s-star class="h-4 w-4" />
                                Best Seller
                            </span>
                        @endif

                        <form method="POST" action="{{ route('rfq-list.store', $product->slug) }}" class="absolute right-3 top-3">
                            @csrf
                            <button type="submit"
                                    class="flex h-10 w-10 items-center justify-center rounded-full bg-white/95 shadow-sm transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 {{ $inRfqList ? 'text-forest-700' : 'text-ink-soft' }}"
                                    aria-pressed="{{ $inRfqList ? 'true' : 'false' }}"
                                    aria-label="{{ $inRfqList ? 'Remove '.$product->name.' from your RFQ list' : 'Add '.$product->name.' to your RFQ list' }}">
                                <x-dynamic-component :component="$inRfqList ? 'heroicon-s-heart' : 'heroicon-o-heart'" class="h-5 w-5" />
                            </button>
                        </form>

                        @if ($hasGallery)
                            <button type="button" @click="go(active - 1)"
                                    class="absolute left-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/95 text-ink shadow-sm transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500"
                                    aria-label="Previous image">
                                <x-heroicon-m-chevron-left class="h-5 w-5" />
                            </button>
                            <button type="button" @click="go(active + 1)"
                                    class="absolute right-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/95 text-ink shadow-sm transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500"
                                    aria-label="Next image">
                                <x-heroicon-m-chevron-right class="h-5 w-5" />
                            </button>

                            {{-- Mobile dot indicators --}}
                            <div class="absolute inset-x-0 bottom-3 flex justify-center gap-1.5 lg:hidden" aria-hidden="true">
                                @foreach ($images as $i => $image)
                                    <span class="h-1.5 rounded-full transition-all"
                                          :class="active === {{ $i }} ? 'w-5 bg-white' : 'w-1.5 bg-white/60'"></span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @if ($hasGallery)
                        <ul class="mt-3 grid grid-cols-5 gap-2 lg:gap-3" role="list" aria-label="Product images">
                            @foreach ($images as $i => $image)
                                <li>
                                    <button type="button" @click="active = {{ $i }}"
                                            :aria-current="active === {{ $i }} ? 'true' : 'false'"
                                            :class="active === {{ $i }} ? 'border-forest-700' : 'border-transparent hover:border-sand-400'"
                                            class="block w-full overflow-hidden rounded-lg border-2 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500"
                                            aria-label="Show image {{ $i + 1 }} of {{ count($images) }}">
                                        <img src="{{ $image['url'] }}" alt="" loading="lazy" width="200" height="150"
                                             class="aspect-[4/3] w-full object-cover">
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- ---------------- Buying panel ---------------- --}}
                <div>
                    <div class="flex flex-wrap items-start gap-x-3 gap-y-2">
                        <h1 class="text-[1.5rem] font-bold leading-tight tracking-tight text-ink lg:text-[1.875rem]">{{ $product->name }}</h1>
                        @if ($verifiedSupplier)
                            <span class="mt-1 inline-flex shrink-0 items-center gap-1.5 rounded-full bg-forest-50 px-3 py-1 text-[0.75rem] font-semibold text-forest-800">
                                <x-heroicon-s-check-badge class="h-4 w-4" />
                                Verified Supplier
                            </span>
                        @endif
                    </div>

                    @if ($species)
                        <p class="mt-1.5 text-[1rem] text-ink-soft">{{ $species->common_name }}@if ($species->scientific_name) <span class="italic">({{ $species->scientific_name }})</span>@endif</p>
                    @endif

                    {{-- Rating row — rendered only when a real rating exists. --}}
                    @if ($product->hasRating())
                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2">
                            <x-star-rating :rating="$product->rating" :count="$product->reviews_count" />
                            @if ((int) $product->buyers_count > 0)
                                <span class="flex items-center gap-1.5 border-l border-sand-300 pl-4 text-[0.8125rem] text-ink-soft">
                                    <x-heroicon-o-user-group class="h-4 w-4" />
                                    {{ number_format($product->buyers_count) }} buyers
                                </span>
                            @endif
                        </div>
                    @endif

                    {{-- Price --}}
                    <div class="mt-4">
                        @if ($product->price_amount !== null)
                            <p class="text-[1.75rem] font-bold leading-none text-forest-700 lg:text-[2rem]">
                                {{ number_format((float) $product->price_amount) }}
                                <span class="text-[1rem] font-semibold text-ink-soft">{{ $product->currencyLabel() }} /{{ $product->price_unit->label() }}</span>
                            </p>
                        @else
                            <p class="text-[1.25rem] font-bold text-forest-700">Price on request</p>
                        @endif
                        @if ($product->moqLabel())
                            <p class="mt-1.5 text-[0.8125rem] text-ink-soft">Minimum order quantity: <span class="font-semibold text-ink">{{ $product->moqLabel() }}</span></p>
                        @endif
                    </div>

                    @if ($product->description)
                        <p class="mt-4 text-[0.9375rem] leading-relaxed text-ink-soft">{{ Str::limit(strip_tags($product->description), 240) }}</p>
                    @endif

                    {{-- Spec list --}}
                    @if ($heroSpecs->isNotEmpty())
                        <dl class="mt-5 divide-y divide-sand-200 border-y border-sand-200">
                            @foreach ($heroSpecs as $row)
                                <div class="flex items-start gap-3 py-2.5">
                                    <dt class="flex w-[11rem] shrink-0 items-center gap-2 text-[0.8125rem] text-ink-soft">
                                        <x-dynamic-component :component="'heroicon-o-'.$row['icon']" class="h-4 w-4 shrink-0 text-forest-600" />
                                        {{ $row['label'] }}
                                    </dt>
                                    <dd class="min-w-0 flex-1 text-[0.8125rem] font-medium text-ink">
                                        @if (! empty($row['accent']))
                                            <span class="inline-flex items-center gap-1.5 text-forest-700">
                                                <x-heroicon-s-check-circle class="h-4 w-4" />
                                                {{ $row['value'] }}
                                            </span>
                                        @elseif (! empty($row['url']))
                                            <a href="{{ $row['url'] }}" class="rounded transition hover:text-forest-800 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">{{ $row['value'] }}</a>
                                        @else
                                            {{ $row['value'] }}
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    {{-- Quantity + unit --}}
                    <form method="GET" action="{{ route('rfq.create') }}" class="mt-5"
                          x-data="{ qty: {{ $product->moq_quantity !== null ? (int) ceil((float) $product->moq_quantity) : 1 }} }">
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="flex items-center rounded-lg border border-sand-300">
                                <button type="button" @click="qty = Math.max(1, qty - 1)"
                                        class="flex h-11 w-11 items-center justify-center rounded-l-lg text-ink transition hover:bg-sand-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500"
                                        aria-label="Decrease quantity">
                                    <x-heroicon-m-minus class="h-4 w-4" />
                                </button>
                                <label for="qty" class="sr-only">Quantity</label>
                                <input id="qty" name="quantity" type="number" min="1" x-model.number="qty"
                                       aria-live="polite"
                                       class="h-11 w-16 border-x border-sand-300 bg-white text-center text-[0.9375rem] font-semibold text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none">
                                <button type="button" @click="qty = qty + 1"
                                        class="flex h-11 w-11 items-center justify-center rounded-r-lg text-ink transition hover:bg-sand-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500"
                                        aria-label="Increase quantity">
                                    <x-heroicon-m-plus class="h-4 w-4" />
                                </button>
                            </div>

                            <div class="relative">
                                <label for="unit" class="sr-only">Unit</label>
                                <select id="unit" name="unit"
                                        class="h-11 appearance-none rounded-lg border border-sand-300 bg-white pl-4 pr-10 text-[0.9375rem] text-ink focus:border-forest-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-forest-200">
                                    @foreach (\App\Enums\PriceUnit::cases() as $unit)
                                        <option value="{{ $unit->value }}" @selected($product->price_unit === $unit)>{{ $unit->label() }}</option>
                                    @endforeach
                                </select>
                                <x-heroicon-m-chevron-down class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-soft" />
                            </div>
                        </div>

                        @if ($species)
                            <input type="hidden" name="species" value="{{ $species->slug }}">
                        @endif

                        <button type="submit" class="{{ $btnPrimary }} mt-4">
                            <x-heroicon-o-paper-airplane class="h-5 w-5" />
                            Request a Quote
                        </button>
                    </form>

                    <form method="POST" action="{{ route('rfq-list.store', $product->slug) }}" class="mt-3">
                        @csrf
                        <button type="submit" class="{{ $btnGhost }}">
                            <x-dynamic-component :component="$inRfqList ? 'heroicon-s-bookmark' : 'heroicon-o-bookmark'" class="h-5 w-5" />
                            {{ $inRfqList ? 'Remove from RFQ List' : 'Add to RFQ List' }}
                            @if ($rfqListCount > 0)
                                <span class="rounded-full bg-forest-50 px-2 py-0.5 text-[0.75rem] font-semibold text-forest-800">{{ $rfqListCount }}</span>
                            @endif
                        </button>
                    </form>

                    <div class="mt-4 flex items-start gap-2.5 rounded-lg bg-sand-100 px-4 py-3">
                        <x-heroicon-s-shield-check class="h-5 w-5 shrink-0 text-forest-700" />
                        <div>
                            <p class="text-[0.8125rem] font-semibold text-ink">Secure Transactions</p>
                            <p class="text-[0.75rem] text-ink-soft">Your information is protected and only shared with the supplier you contact.</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ============================================================
                 SPECIFICATIONS + SUPPLIER
            ============================================================= --}}
            <div class="mt-8 grid gap-6 lg:grid-cols-2">

                @if ($specRows !== [])
                    <section class="{{ $card }} overflow-hidden" aria-labelledby="specs-heading">
                        <h2 id="specs-heading" class="border-b border-sand-200 bg-sand-100 px-5 py-3.5 text-[1.0625rem] font-bold text-ink">Product Specifications</h2>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-[0.875rem]">
                                <caption class="sr-only">Technical specifications for {{ $product->name }}</caption>
                                <tbody>
                                    @foreach ($specRows as $i => $row)
                                        <tr @class(['bg-sand-50' => $i % 2 === 1])>
                                            <th scope="row" class="w-2/5 px-5 py-2.5 font-normal text-ink-soft">{{ $row['label'] }}</th>
                                            <td class="px-5 py-2.5 font-medium text-ink">{{ $row['value'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif

                @if ($company)
                    <section class="{{ $card }} overflow-hidden" aria-labelledby="supplier-heading">
                        <h2 id="supplier-heading" class="border-b border-sand-200 bg-sand-100 px-5 py-3.5 text-[1.0625rem] font-bold text-ink">Supplier Information</h2>

                        <div class="p-5">
                            <div class="flex items-start gap-4">
                                <img src="{{ $company->logoUrl() }}" alt="" loading="lazy" width="136" height="136"
                                     class="h-16 w-16 shrink-0 rounded-full bg-white object-cover ring-1 ring-sand-200">
                                <div class="min-w-0 flex-1">
                                    <h3 class="flex flex-wrap items-center gap-2 text-[1.0625rem] font-bold text-ink">
                                        <a href="{{ route('companies.show', $company->slug) }}" class="rounded transition hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500">{{ $company->name }}</a>
                                        @if ($verifiedSupplier)
                                            <x-heroicon-s-check-badge class="h-5 w-5 text-forest-600" />
                                            <span class="sr-only">Verified supplier</span>
                                        @endif
                                    </h3>

                                    <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                                        @if ($company->hasRating())
                                            <x-star-rating :rating="$company->rating_avg" :count="$company->rating_count" size="h-3.5 w-3.5" />
                                        @endif
                                        @if ($verifiedSupplier)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-forest-50 px-2.5 py-0.5 text-[0.6875rem] font-semibold text-forest-800">
                                                <x-heroicon-s-check-badge class="h-3.5 w-3.5" />
                                                Verified Supplier
                                            </span>
                                        @endif
                                    </div>

                                    @if ($company->city)
                                        <p class="mt-1.5 flex items-center gap-1.5 text-[0.8125rem] text-ink-soft">
                                            <x-heroicon-s-map-pin class="h-4 w-4 text-forest-600" />
                                            Based in {{ $company->city }}, Cameroon
                                        </p>
                                    @endif
                                </div>
                            </div>

                            @if ($supplierStats->isNotEmpty())
                                <dl class="mt-5 grid rounded-lg bg-sand-100 py-3 text-center"
                                    style="grid-template-columns: repeat({{ $supplierStats->count() }}, minmax(0, 1fr));">
                                    @foreach ($supplierStats as $i => $stat)
                                        <div @class(['border-l border-sand-300' => $i > 0])>
                                            <dt class="text-[0.6875rem] text-ink-soft">{{ $stat['label'] }}</dt>
                                            <dd class="mt-0.5 text-[1rem] font-bold text-ink">{{ $stat['value'] }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif

                            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                                <a href="{{ route('companies.show', $company->slug) }}" class="{{ $btnGhost }}">View Supplier Profile</a>
                                {{-- In-platform thread, carrying this product
                                     as context. Guests keep the old anchor. --}}
                                <x-message-supplier :company="$company" :product="$product"
                                                    label="Send Message"
                                                    :fallback="route('companies.show', $company->slug).'#contact'"
                                                    :class="$btnPrimary" />
                            </div>

                            @if ($supplierMeta->isNotEmpty())
                                <dl class="mt-5 space-y-2 border-t border-sand-200 pt-4">
                                    @foreach ($supplierMeta as $row)
                                        <div class="flex items-start gap-3">
                                            <dt class="flex w-[9rem] shrink-0 items-center gap-2 text-[0.8125rem] text-ink-soft">
                                                <x-dynamic-component :component="'heroicon-o-'.$row['icon']" class="h-4 w-4 shrink-0 text-forest-600" />
                                                {{ $row['label'] }}
                                            </dt>
                                            <dd class="min-w-0 flex-1 text-[0.8125rem] font-medium text-ink">{{ $row['value'] }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif
                        </div>
                    </section>
                @endif
            </div>

            {{-- ============================================================
                 TABS — every panel is rendered server-side
            ============================================================= --}}
            <section class="{{ $card }} mt-8 overflow-hidden"
                     x-data="{
                        tab: '{{ $firstTab }}',
                        tabs: @js($tabs->pluck('id')->all()),
                        move(step) {
                            const i = this.tabs.indexOf(this.tab);
                            const next = (i + step + this.tabs.length) % this.tabs.length;
                            this.tab = this.tabs[next];
                            this.$refs['tab-' + this.tabs[next]].focus();
                        },
                     }"
                     aria-labelledby="tabs-heading">
                <h2 id="tabs-heading" class="sr-only">Product information</h2>

                <div class="overflow-x-auto border-b border-sand-200 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <div role="tablist" aria-label="Product information" class="flex min-w-max px-2">
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
                                    class="border-b-2 px-4 py-3.5 text-[0.875rem] font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500">
                                {{ $t['label'] }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="p-5 lg:p-6">
                    @foreach ($tabs as $t)
                        <div role="tabpanel"
                             id="panel-{{ $t['id'] }}"
                             aria-labelledby="tab-{{ $t['id'] }}"
                             tabindex="0"
                             x-show="tab === '{{ $t['id'] }}'"
                             @if ($t['id'] !== $firstTab) x-cloak @endif>

                            @switch($t['id'])
                                @case('description')
                                    <div class="grid gap-6 lg:grid-cols-[1.7fr_1fr]">
                                        <div>
                                            @if ($product->description)
                                                <div class="space-y-3 text-[0.9375rem] leading-relaxed text-ink-soft">
                                                    @foreach (preg_split('/\n{2,}|(?<=\.)\s{2,}/', (string) $product->description) as $para)
                                                        @if (trim($para) !== '')
                                                            <p>{{ trim($para) }}</p>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            @endif

                                            @if ($benefits->isNotEmpty())
                                                <h3 class="mt-5 text-[0.9375rem] font-bold text-ink">Key Benefits</h3>
                                                <ul class="mt-2.5 space-y-1.5">
                                                    @foreach ($benefits as $benefit)
                                                        <li class="flex items-start gap-2 text-[0.875rem] text-ink-soft">
                                                            <x-heroicon-s-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-forest-600" />
                                                            {{ $benefit }}
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>

                                        <aside class="h-fit rounded-xl bg-sand-100 p-5">
                                            <h3 class="text-[1rem] font-bold text-ink">Have Questions?</h3>
                                            <p class="mt-1.5 text-[0.8125rem] leading-relaxed text-ink-soft">Our timber experts are ready to help you find the right product.</p>
                                            <a href="{{ route('contact') }}"
                                               class="mt-4 inline-flex items-center gap-2 rounded-lg bg-forest-700 px-4 py-2.5 text-[0.875rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                                                <x-heroicon-o-chat-bubble-left-right class="h-4 w-4" />
                                                Chat with Expert
                                            </a>
                                            <ul class="mt-4 space-y-2 border-t border-sand-300 pt-4">
                                                @foreach ([
                                                    ['Get product advice', route('contact'), 'light-bulb'],
                                                    ['Request custom specifications', route('rfq.create'), 'adjustments-horizontal'],
                                                    ['Bulk order support', route('rfq.create'), 'cube'],
                                                ] as [$label, $href, $icon])
                                                    <li>
                                                        <a href="{{ $href }}" class="flex items-center gap-2 rounded text-[0.8125rem] text-ink-soft transition hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">
                                                            <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-4 w-4 text-forest-600" />
                                                            {{ $label }}
                                                        </a>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </aside>
                                    </div>
                                    @break

                                @case('details')
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-left text-[0.875rem]">
                                            <caption class="sr-only">Full specification table for {{ $product->name }}</caption>
                                            <tbody>
                                                @foreach ($specRows as $i => $row)
                                                    <tr @class(['bg-sand-50' => $i % 2 === 1])>
                                                        <th scope="row" class="w-2/5 px-4 py-2.5 font-normal text-ink-soft">{{ $row['label'] }}</th>
                                                        <td class="px-4 py-2.5 font-medium text-ink">{{ $row['value'] }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    @break

                                @case('shipping')
                                    <dl class="grid gap-4 sm:grid-cols-2">
                                        @foreach (collect([
                                            ['Origin', $product->origin],
                                            ['Loading port', $company?->region === 'Littoral' ? 'Douala' : null],
                                            ['Minimum order', $product->moqLabel()],
                                            ['Incoterms', 'Quoted per request — EXW, FOB, CFR, CIF or DAP.'],
                                            ['Lead time', $company?->responseTimeLabel() ? 'Quotation typically within '.$company->responseTimeLabel() : null],
                                        ])->filter(fn ($r) => filled($r[1])) as [$label, $value])
                                            <div class="rounded-lg border border-sand-200 p-4">
                                                <dt class="text-[0.75rem] font-semibold uppercase tracking-wide text-ink-soft">{{ $label }}</dt>
                                                <dd class="mt-1 text-[0.875rem] text-ink">{{ $value }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                    <p class="mt-4 text-[0.8125rem] text-ink-soft">
                                        Shipping terms, packing and delivery schedule are agreed directly with the supplier when a quotation is issued.
                                    </p>
                                    @break

                                @case('certifications')
                                    <ul class="space-y-3">
                                        @if ($product->certification)
                                            <li class="flex items-start gap-3 rounded-lg border border-forest-100 bg-forest-50 p-4">
                                                <x-heroicon-s-shield-check class="mt-0.5 h-5 w-5 shrink-0 text-forest-700" />
                                                <div>
                                                    <p class="text-[0.875rem] font-semibold text-ink">{{ $product->certification }}</p>
                                                    <p class="text-[0.8125rem] text-ink-soft">Recorded against this listing by the supplier.</p>
                                                </div>
                                            </li>
                                        @endif
                                        @foreach ($company?->activeBadges ?? [] as $badge)
                                            <li class="flex items-start gap-3 rounded-lg border border-sand-200 p-4">
                                                <x-heroicon-s-check-badge class="mt-0.5 h-5 w-5 shrink-0 text-forest-600" />
                                                <div>
                                                    <p class="text-[0.875rem] font-semibold text-ink">{{ $badge->badge_type?->label() }}</p>
                                                    @if ($badge->valid_until)
                                                        <p class="text-[0.8125rem] text-ink-soft">Valid until {{ $badge->valid_until->format('j M Y') }}</p>
                                                    @endif
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @break

                                @case('reviews')
                                    @if ($product->hasRating())
                                        <div class="flex flex-wrap items-center gap-5 rounded-xl bg-sand-100 p-5">
                                            <p class="text-[2.5rem] font-bold leading-none text-ink">{{ rtrim(rtrim(number_format((float) $product->rating, 1), '0'), '.') }}</p>
                                            <div>
                                                <x-star-rating :rating="$product->rating" :count="$product->reviews_count" />
                                                <p class="mt-1 text-[0.8125rem] text-ink-soft">Average score reported by buyers who purchased this listing.</p>
                                            </div>
                                        </div>
                                    @endif
                                    {{-- Individual reviews are not published: the Hub does not yet
                                         collect written buyer reviews, so none are invented here. --}}
                                    <div class="mt-4 rounded-xl border border-dashed border-sand-300 p-8 text-center">
                                        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-forest-50 text-forest-700">
                                            <x-heroicon-o-chat-bubble-bottom-center-text class="h-6 w-6" />
                                        </span>
                                        <p class="mt-3 text-[0.9375rem] font-bold text-ink">Written reviews aren't published yet</p>
                                        <p class="mt-1.5 text-[0.875rem] text-ink-soft">
                                            We publish written buyer reviews only once they are verified against a completed order. Contact the supplier for trade references.
                                        </p>
                                    </div>
                                    @break
                            @endswitch
                        </div>
                    @endforeach
                </div>
            </section>

        </div>
        </div>

        {{-- ============================================================
             SHARED — rendered once for both layouts
        ============================================================= --}}
        <div class="mx-auto max-w-[1400px] px-4 pb-4 lg:px-6 lg:pb-6">
            {{-- ============================================================
                 SIMILAR PRODUCTS
            ============================================================= --}}
            @if ($similar->isNotEmpty())
                <section class="mt-10" aria-labelledby="similar-heading">
                    <div class="flex items-center gap-3">
                        <h2 id="similar-heading" class="text-[1.25rem] font-bold text-ink lg:text-[1.5rem]">Similar Products</h2>
                        <a href="{{ route('marketplace', ['type' => $product->product_type->value]) }}"
                           class="ml-auto inline-flex items-center gap-1.5 rounded text-[0.875rem] font-semibold text-forest-700 transition hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">
                            View All <x-heroicon-m-arrow-right class="h-4 w-4" />
                        </a>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                        @foreach ($similar as $item)
                            <x-product-card :product="$item" compact />
                        @endforeach
                    </div>
                </section>
            @endif
        </div>

        {{-- ============================================================
             CTA band
        ============================================================= --}}
        <section class="mt-10 bg-forest-950">
            <div class="mx-auto flex max-w-[1400px] flex-col gap-5 px-4 py-10 lg:flex-row lg:items-center lg:px-6 lg:py-12">
                <div class="min-w-0 flex-1">
                    <h2 class="text-[1.5rem] font-bold text-white lg:text-[1.875rem]">Can't find what you're looking for?</h2>
                    <p class="mt-2 text-[0.9375rem] text-forest-100">Post an RFQ and get quotations from multiple verified suppliers.</p>
                </div>
                <a href="{{ route('rfq.create') }}"
                   class="inline-flex shrink-0 items-center gap-2 self-start rounded-lg bg-white px-6 py-3.5 text-[0.9375rem] font-semibold text-forest-800 transition hover:bg-forest-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-forest-950 lg:self-auto">
                    <x-heroicon-o-paper-airplane class="h-5 w-5" />
                    Post an RFQ Now
                </a>
            </div>
        </section>
    </div>

    {{-- ============================================================
         MOBILE sticky action bar — sits above the app tab bar
    ============================================================= --}}
    <x-product-mobile.actions :product="$product" />
</x-layouts.app>
