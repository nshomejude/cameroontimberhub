<x-layouts.app
    :title="$company->name"
    :description="Str::limit(strip_tags($company->description), 160)"
    :schema="$schema">

    <section class="border-b border-stone-200 bg-white">
        <div class="mx-auto max-w-6xl px-4 py-10">
            <nav class="mb-4 text-sm text-stone-500">
                <a href="{{ route('directory') }}" class="hover:text-amber-700">Exporters</a>
                <span class="mx-1">/</span>
                <span class="text-stone-700">{{ $company->name }}</span>
            </nav>
            <div class="flex items-start gap-4">
                <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-xl font-bold text-amber-800">
                    {{ strtoupper(Str::substr($company->name, 0, 2)) }}
                </span>
                <div>
                    <h1 class="text-3xl font-bold text-stone-900">{{ $company->name }}</h1>
                    <p class="mt-1 text-stone-600">{{ $company->region }}@if($company->city), {{ $company->city }}@endif · {{ $company->country_code }}</p>
                    <p class="mt-2 inline-flex items-center gap-1 rounded-full bg-green-50 px-3 py-1 text-sm font-medium text-green-700">&check; Verified profile</p>
                </div>
            </div>
        </div>
    </section>

    <div class="mx-auto grid max-w-6xl gap-8 px-4 py-10 lg:grid-cols-3">
        <div class="space-y-8 lg:col-span-2">
            <section>
                <h2 class="text-lg font-semibold text-stone-900">About</h2>
                <div class="mt-3 whitespace-pre-line text-stone-700">{{ $company->description }}</div>
            </section>

            @if($company->species->isNotEmpty())
                <section>
                    <h2 class="text-lg font-semibold text-stone-900">Species handled</h2>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach($company->species as $sp)
                            <a href="{{ route('species.show', $sp->slug) }}" class="rounded-md border border-stone-200 bg-white px-3 py-1.5 text-sm text-stone-700 hover:border-amber-500">
                                {{ $sp->common_name }}
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            @if($company->exportMarkets->isNotEmpty())
                <section>
                    <h2 class="text-lg font-semibold text-stone-900">Export markets</h2>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach($company->exportMarkets as $market)
                            <span class="rounded bg-stone-100 px-2.5 py-1 text-sm text-stone-600">{{ $market->country_code }}</span>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>

        <aside class="space-y-6">
            <section class="rounded-lg border border-stone-200 bg-white p-5">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-stone-500">Verification</h2>
                <p class="mt-3 font-medium text-green-700">&check; Verified profile</p>
                <dl class="mt-3 space-y-1 text-sm text-stone-600">
                    <div class="flex justify-between"><dt>Verification date</dt><dd>{{ optional($badge?->issued_at)->format('d M Y') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt>Valid until</dt><dd>{{ optional($badge?->valid_until)->format('d M Y') ?? '—' }}</dd></div>
                </dl>
                <p class="mt-4 text-xs leading-relaxed text-stone-400">
                    Documents reviewed by Cameroon Timber Hub based on information submitted by the company.
                    Buyers should conduct final due diligence before any transaction.
                </p>
            </section>

            @if($company->contacts->isNotEmpty())
                <section class="rounded-lg border border-stone-200 bg-white p-5">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-stone-500">Contacts</h2>
                    <ul class="mt-3 space-y-3 text-sm">
                        @foreach($company->contacts as $contact)
                            <li>
                                <p class="font-medium text-stone-800">{{ $contact->name }}@if($contact->title)<span class="font-normal text-stone-500"> · {{ $contact->title }}</span>@endif</p>
                                @if($contact->email)<p class="text-stone-600">{{ $contact->email }}</p>@endif
                                @if($contact->phone)<p class="text-stone-600">{{ $contact->phone }}</p>@endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section class="rounded-lg border border-amber-200 bg-amber-50 p-5">
                <h2 class="font-semibold text-amber-900">Interested in this supplier?</h2>
                <p class="mt-1 text-sm text-amber-800">Send a request for quote — no account needed.</p>
                <a href="#" class="mt-3 inline-block rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Request a quote</a>
            </section>
        </aside>
    </div>
</x-layouts.app>
