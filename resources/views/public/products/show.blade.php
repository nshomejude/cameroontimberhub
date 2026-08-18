{{-- Functional placeholder: replaced by the pixel-perfect product detail design. --}}
<x-layouts.app
    :title="$product->name"
    :description="$product->meta_description ?: Str::limit(strip_tags((string) $product->description), 160)"
    :schema="$schema">

    <section class="mx-auto max-w-6xl px-4 py-10">
        <nav class="mb-6 text-sm text-ink-soft dark:text-[#b3ab9b]">
            <a href="{{ route('marketplace') }}" class="hover:underline">Marketplace</a> /
            <span>{{ $product->name }}</span>
        </nav>

        <div class="grid gap-10 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <h1 class="font-display text-3xl font-semibold text-forest-950 dark:text-sand-100">{{ $product->name }}</h1>

                @if($product->is_best_seller)
                    <span class="mt-2 inline-block rounded-full bg-timber-100 px-2.5 py-0.5 text-xs font-semibold text-timber-800">Best Seller</span>
                @endif

                @if($product->rating !== null)
                    <p class="mt-2 text-sm text-ink-soft dark:text-[#b3ab9b]">
                        {{ $product->rating }} ({{ $product->reviews_count }} reviews) &middot; {{ $product->buyers_count }} buyers
                    </p>
                @endif

                @if($product->price_amount !== null)
                    <p class="mt-4 text-2xl font-semibold text-forest-700 dark:text-forest-400">
                        {{ number_format((float) $product->price_amount) }} {{ $product->price_currency }} / {{ $product->price_unit->label() }}
                    </p>
                @endif

                @if($product->description)
                    <p class="mt-4 leading-relaxed text-ink-soft dark:text-[#b3ab9b]">{{ $product->description }}</p>
                @endif

                <dl class="mt-6 grid gap-2 sm:grid-cols-2">
                    @if($product->species)
                        <div><dt class="text-xs uppercase text-ink-soft">Species</dt><dd><a class="hover:underline" href="{{ route('species.show', $product->species->slug) }}">{{ $product->species->common_name }}</a></dd></div>
                    @endif
                    <div><dt class="text-xs uppercase text-ink-soft">Product Type</dt><dd>{{ $product->product_type->label() }}</dd></div>
                    @if($product->thickness_mm)<div><dt class="text-xs uppercase text-ink-soft">Thickness</dt><dd>{{ (float) $product->thickness_mm }}mm</dd></div>@endif
                    @if($product->width_min_mm)<div><dt class="text-xs uppercase text-ink-soft">Width</dt><dd>{{ (float) $product->width_min_mm }}mm - {{ (float) $product->width_max_mm }}mm</dd></div>@endif
                    @if($product->length_min_m)<div><dt class="text-xs uppercase text-ink-soft">Length</dt><dd>{{ (float) $product->length_min_m }}m - {{ (float) $product->length_max_m }}m</dd></div>@endif
                    @if($product->moisture_content)<div><dt class="text-xs uppercase text-ink-soft">Moisture Content</dt><dd>{{ $product->moisture_content }}</dd></div>@endif
                    @if($product->grade)<div><dt class="text-xs uppercase text-ink-soft">Grade</dt><dd>{{ $product->grade }}</dd></div>@endif
                    <div><dt class="text-xs uppercase text-ink-soft">Origin</dt><dd>{{ $product->origin }}</dd></div>
                    @if($product->certification)<div><dt class="text-xs uppercase text-ink-soft">Certification</dt><dd>{{ $product->certification }}</dd></div>@endif
                    @if($product->moq_quantity !== null)<div><dt class="text-xs uppercase text-ink-soft">MOQ</dt><dd>{{ rtrim(rtrim(number_format((float) $product->moq_quantity, 2), '0'), '.') }} {{ $product->moq_unit->label() }}</dd></div>@endif
                </dl>

                @if(is_array($product->specifications) && count($product->specifications))
                    <h2 class="mt-10 font-display text-2xl font-semibold text-forest-950 dark:text-sand-100">Product Specifications</h2>
                    <dl class="mt-4 grid gap-2 sm:grid-cols-2">
                        @foreach($product->specifications as $key => $value)
                            <div><dt class="text-xs uppercase text-ink-soft">{{ Str::headline((string) $key) }}</dt><dd>{{ is_array($value) ? implode(', ', $value) : $value }}</dd></div>
                        @endforeach
                    </dl>
                @endif

                @if(is_array($product->key_benefits) && count($product->key_benefits))
                    <h2 class="mt-10 font-display text-2xl font-semibold text-forest-950 dark:text-sand-100">Key Benefits</h2>
                    <ul class="mt-3 list-disc space-y-1 pl-5 text-ink-soft dark:text-[#b3ab9b]">
                        @foreach($product->key_benefits as $benefit)
                            <li>{{ $benefit }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <aside>
                <div class="rounded-2xl border border-sand-200 bg-white p-6 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                    <h2 class="font-display text-lg font-semibold text-forest-950 dark:text-sand-100">Supplier Information</h2>
                    @if($product->company)
                        <p class="mt-2 font-semibold">{{ $product->company->trade_name ?: $product->company->legal_name }}</p>
                        <p class="text-sm text-ink-soft dark:text-[#b3ab9b]">Based in {{ $product->company->city }}, Cameroon</p>
                        <a href="{{ route('companies.show', $product->company->slug) }}" class="mt-4 inline-block rounded-full border border-forest-700 px-4 py-2 text-sm font-semibold text-forest-800 dark:text-forest-300">View Supplier Profile</a>
                    @endif
                    <a href="{{ route('rfq.create') }}" class="mt-3 inline-block rounded-full bg-forest-800 px-5 py-2.5 text-sm font-semibold text-white">Request a Quote</a>
                </div>
            </aside>
        </div>

        @if($similar->isNotEmpty())
            <h2 class="mt-14 font-display text-2xl font-semibold text-forest-950 dark:text-sand-100">Similar Products</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($similar as $item)
                    <a href="{{ route('products.show', $item->slug) }}" class="rounded-xl border border-sand-200 p-4 hover:shadow dark:border-[#2c2a24]">
                        <p class="font-semibold">{{ $item->name }}</p>
                        @if($item->price_amount !== null)
                            <p class="text-sm text-forest-700 dark:text-forest-400">{{ number_format((float) $item->price_amount) }} {{ $item->price_currency }} / {{ $item->price_unit->label() }}</p>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</x-layouts.app>
