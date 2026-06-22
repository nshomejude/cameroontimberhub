<x-layouts.app
    :title="$company->name"
    :description="Str::limit(strip_tags($company->description), 160)"
    :schema="$schema">

    {{-- Cover / header --}}
    <section class="relative overflow-hidden border-b border-sand-200 bg-gradient-to-br from-forest-800 to-forest-950 text-sand-100">
        <x-brand-mark class="pointer-events-none absolute -right-16 -top-16 h-80 w-80 opacity-10" />
        <div class="relative mx-auto max-w-6xl px-4 py-12">
            <nav class="mb-6 flex items-center gap-1.5 text-sm text-forest-200">
                <a href="{{ route('directory') }}" class="transition hover:text-white">Exporters</a>
                <x-heroicon-m-chevron-right class="h-4 w-4" />
                <span class="text-sand-100">{{ $company->name }}</span>
            </nav>
            <div class="flex flex-wrap items-center gap-5">
                <span class="flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl bg-sand-100 font-display text-3xl font-semibold text-forest-800">
                    {{ strtoupper(Str::substr($company->name, 0, 1)) }}
                </span>
                <div>
                    <h1 class="font-display text-4xl font-semibold text-white">{{ $company->name }}</h1>
                    <p class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-forest-200">
                        <span class="inline-flex items-center gap-1.5"><x-heroicon-m-map-pin class="h-4 w-4 text-timber-300" />{{ $company->region }}@if($company->city), {{ $company->city }}@endif</span>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-forest-700/70 px-3 py-1 text-sm font-medium text-white"><x-heroicon-s-check-badge class="h-4 w-4 text-timber-300" />Verified profile</span>
                    </p>
                </div>
            </div>
        </div>
    </section>

    <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 lg:grid-cols-3">
        <div class="space-y-10 lg:col-span-2">
            <section>
                <h2 class="font-display text-2xl font-semibold text-forest-950">About</h2>
                <div class="mt-4 whitespace-pre-line leading-relaxed text-ink-soft">{{ $company->description }}</div>
            </section>

            @if($company->species->isNotEmpty())
                <section>
                    <h2 class="font-display text-2xl font-semibold text-forest-950">Species handled</h2>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach($company->species as $sp)
                            <a href="{{ route('species.show', $sp->slug) }}" class="inline-flex items-center gap-1.5 rounded-full border border-sand-200 bg-white px-4 py-2 text-sm font-medium text-forest-800 transition hover:border-forest-300 hover:bg-forest-50">
                                {{ $sp->common_name }}
                                <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 text-timber-500" />
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            @if($company->exportMarkets->isNotEmpty())
                <section>
                    <h2 class="font-display text-2xl font-semibold text-forest-950">Export markets</h2>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach($company->exportMarkets as $market)
                            <span class="rounded-lg bg-sand-100 px-3 py-1.5 text-sm font-medium text-ink-soft">{{ $market->country_code }}</span>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>

        <aside class="space-y-6">
            <section class="rounded-2xl border border-sand-200 bg-white p-6 shadow-sm">
                <h2 class="flex items-center gap-2 font-display text-lg font-semibold text-forest-900">
                    <x-heroicon-s-shield-check class="h-5 w-5 text-forest-600" /> Verification
                </h2>
                @if($badges->isNotEmpty())
                    <ul class="mt-3 space-y-1.5">
                        @foreach($badges as $activeBadge)
                            <li class="flex items-center gap-2 text-sm font-medium text-forest-700">
                                <x-heroicon-s-check-badge class="h-4 w-4 text-forest-600" />{{ $activeBadge->badge_type->label() }}
                            </li>
                        @endforeach
                    </ul>
                @endif
                <dl class="mt-4 space-y-2.5 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-soft">Status</dt><dd class="font-medium text-forest-700">Verified profile</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-soft">Verification date</dt><dd class="text-ink">{{ optional($badge?->issued_at)->format('d M Y') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-soft">Valid until</dt><dd class="text-ink">{{ optional($badge?->valid_until)->format('d M Y') ?? '—' }}</dd></div>
                    @if($badge?->reference_code)
                        <div class="flex justify-between"><dt class="text-ink-soft">Reference</dt><dd class="text-ink">{{ $badge->reference_code }}</dd></div>
                    @endif
                </dl>
                <p class="mt-4 border-t border-sand-100 pt-4 text-xs leading-relaxed text-ink-soft">
                    Documents reviewed by Cameroon Timber Hub based on information submitted by the company.
                    Buyers should conduct final due diligence before any transaction.
                </p>
            </section>

            @if($company->contacts->isNotEmpty())
                <section class="rounded-2xl border border-sand-200 bg-white p-6 shadow-sm">
                    <h2 class="font-display text-lg font-semibold text-forest-900">Contacts</h2>
                    <ul class="mt-4 space-y-4 text-sm">
                        @foreach($company->contacts as $contact)
                            <li>
                                <p class="font-medium text-ink">{{ $contact->name }}@if($contact->title)<span class="font-normal text-ink-soft"> · {{ $contact->title }}</span>@endif</p>
                                @if($contact->email)<p class="mt-1 flex items-center gap-1.5 text-ink-soft"><x-heroicon-m-envelope class="h-4 w-4 text-timber-500" />{{ $contact->email }}</p>@endif
                                @if($contact->phone)<p class="mt-1 flex items-center gap-1.5 text-ink-soft"><x-heroicon-m-phone class="h-4 w-4 text-timber-500" />{{ $contact->phone }}</p>@endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @php($f = 'w-full rounded-lg border border-sand-300 bg-white px-3 py-2 text-sm text-ink focus:border-forest-500 focus:ring-2 focus:ring-forest-100 focus:outline-none')
            <section class="rounded-2xl border border-sand-200 bg-white p-6 shadow-sm">
                <h2 class="font-display text-lg font-semibold text-forest-900">Contact this exporter</h2>
                @if (session('inquiry_sent'))
                    <p class="mt-3 rounded-lg bg-forest-50 p-3 text-sm text-forest-700">Thanks — check your email to confirm and deliver your message.</p>
                @else
                    <form method="POST" action="{{ route('inquiry.store', $company->slug) }}" class="mt-4 space-y-3">
                        @csrf
                        <input type="hidden" name="form_rendered_at" value="{{ now()->timestamp }}">
                        <div class="hidden" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
                        <input type="text" name="name" value="{{ old('name') }}" class="{{ $f }}" placeholder="Your name *" required>
                        <input type="email" name="email" value="{{ old('email') }}" class="{{ $f }}" placeholder="Email *" required>
                        <input type="text" name="phone" value="{{ old('phone') }}" class="{{ $f }}" placeholder="Phone (optional)">
                        <textarea name="message" rows="3" class="{{ $f }}" placeholder="Your message (min 20 characters) *" required>{{ old('message') }}</textarea>
                        <label class="flex items-start gap-2 text-xs text-ink-soft">
                            <input type="checkbox" name="consent" value="1" class="mt-0.5 rounded border-sand-300" required>
                            <span>I consent to be contacted by email about this inquiry.</span>
                        </label>
                        <button class="w-full rounded-full bg-forest-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">Send message</button>
                    </form>
                @endif
                <a href="{{ route('rfq.create') }}" class="mt-3 inline-block text-sm font-medium text-forest-700 transition hover:text-forest-900">Or request a multi-supplier quote &rarr;</a>
            </section>
        </aside>
    </div>
</x-layouts.app>
