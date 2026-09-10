@php
    // Public receipt verification (desktop + mobile comps).
    //
    // Everything rendered below comes from ReceiptVerifier::publicPayload() —
    // a hand-written allow-list. The Receipt and Order models are deliberately
    // NOT passed to this view, so no one can reach through a relation in Blade
    // and print the buyer, the line items or an internal note.
    //
    // The mockups' "blockchain secured / immutable record / block number /
    // confirmations" panels are omitted entirely: there is no blockchain here
    // and inventing one would be a lie about how the record is protected.
@endphp

<x-layouts.app
    title="Verify a receipt"
    description="Check whether a Cameroon Timber Hub receipt is genuine."
    noindex>

    {{-- ---------------- Hero ---------------- --}}
    <section class="bg-forest-950 text-white">
        <div class="mx-auto max-w-5xl px-4 py-10 sm:py-14">
            <div class="flex items-start gap-4">
                <span class="hidden h-12 w-12 shrink-0 items-center justify-center rounded-full bg-white/10 text-forest-200 sm:flex">
                    <x-heroicon-o-shield-check class="h-7 w-7" />
                </span>
                <div>
                    <h1 class="font-display text-2xl font-semibold sm:text-3xl">Verify a receipt</h1>
                    <p class="mt-2 max-w-2xl text-[1.125rem] text-forest-100/80">
                        Enter the receipt number printed on a Cameroon Timber Hub receipt to check that we issued it,
                        and that its supplier, date and amount match the document in front of you.
                    </p>
                </div>
            </div>

            <ul class="mt-6 grid gap-3 sm:grid-cols-3">
                @foreach ([
                    ['Issued by this platform', 'Every receipt is created by us when an order is awarded.'],
                    ['Checked against our records', 'The supplier, date and amount come straight from the order.'],
                    ['Private by design', 'Verification never reveals who the buyer is or what was ordered.'],
                ] as [$heading, $copy])
                    <li class="rounded-xl bg-white/5 px-4 py-3">
                        <p class="text-[1.0625rem] font-semibold text-white">{{ $heading }}</p>
                        <p class="mt-1 text-[0.9375rem] leading-relaxed text-forest-100/70">{{ $copy }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    <div class="mx-auto max-w-5xl px-4 py-8 sm:py-12">

        {{-- ---------------- Lookup form ---------------- --}}
        <section aria-labelledby="verify-form"
                 class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18] sm:p-7">
            <h2 id="verify-form" class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">Enter receipt number</h2>
            <p class="mt-1 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                For example: RCT-{{ date('Y') }}-A1B2C
            </p>

            <form method="POST" action="{{ route('receipts.verify.store') }}" class="mt-4 flex flex-col gap-3 sm:flex-row">
                @csrf
                <label for="reference" class="sr-only">Receipt number</label>
                <input type="text" id="reference" name="reference" value="{{ $reference }}" required maxlength="64"
                       autocomplete="off" spellcheck="false"
                       placeholder="RCT-{{ date('Y') }}-A1B2C"
                       class="w-full rounded-xl border border-sand-300 bg-white px-4 py-3 text-[1.125rem] text-ink placeholder:text-ink-soft/60 focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500/30 dark:border-[#3a372f] dark:bg-[#26241e] dark:text-[#e4ddcf]">
                <button type="submit"
                        class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-forest-700 px-6 py-3 text-[1.125rem] font-semibold text-white transition hover:bg-forest-800">
                    <x-heroicon-m-magnifying-glass class="h-4 w-4" /> Verify receipt
                </button>
            </form>

            @error('reference')
                <p role="alert" class="mt-2 text-[1.0625rem] text-red-700 dark:text-red-300">{{ $message }}</p>
            @enderror
        </section>

        {{-- ---------------- Result ---------------- --}}
        @if ($searched && ! $result)
            <section role="status"
                     class="mt-6 rounded-2xl border border-red-200 bg-red-50 p-5 dark:border-red-900 dark:bg-red-950 sm:p-7">
                <div class="flex items-start gap-3">
                    <x-heroicon-o-x-circle class="mt-0.5 h-6 w-6 shrink-0 text-red-600 dark:text-red-300" />
                    <div>
                        <h2 class="font-display text-lg font-bold text-red-900 dark:text-red-100">Not recognised</h2>
                        <p class="mt-1 text-[1.0625rem] text-red-800 dark:text-red-200">
                            We hold no receipt with that number. Check the number for typos. If it is printed on a
                            document claiming to come from {{ config('app.name') }}, treat that document as unverified.
                        </p>
                    </div>
                </div>
            </section>
        @elseif ($result)
            <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">

                <section aria-labelledby="verify-result"
                         class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18] sm:p-7">
                    <h2 id="verify-result" class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">Receipt details</h2>
                    <p class="mt-1 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                        This is the complete set of facts we disclose about a receipt.
                    </p>

                    <dl class="mt-4 divide-y divide-sand-200 dark:divide-[#2c2a24]">
                        @foreach ([
                            'Issued by' => $result['issuer'],
                            'Receipt number' => $result['receipt_number'],
                            'Order reference' => $result['order_reference'],
                            'Date issued' => $result['issued_at']->isoFormat('D MMM YYYY, HH:mm'),
                            'Amount' => $result['amount'],
                            'Supplier' => $result['supplier_name'].($result['supplier_verified'] ? ' (verified supplier)' : ''),
                            'Order status' => $result['order_status'],
                        ] as $label => $value)
                            <div class="flex flex-wrap justify-between gap-2 py-3 text-[1.125rem]">
                                <dt class="text-ink-soft dark:text-[#8f887b]">{{ $label }}</dt>
                                <dd class="text-right font-semibold text-ink dark:text-[#e4ddcf]">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <p class="mt-4 rounded-xl bg-sand-100 px-4 py-3 text-[1.0625rem] leading-relaxed text-ink-soft dark:bg-[#26241e] dark:text-[#b3ab9b]">
                        A receipt records an order placed on this marketplace. It is not a proof of payment —
                        {{ config('app.name') }} processes no payments. The buyer's identity and the order's line items
                        are private and are never shown here.
                    </p>
                </section>

                <aside class="space-y-4">
                    <section @class([
                        'rounded-2xl border p-5',
                        'border-forest-200 bg-forest-50 dark:border-forest-900 dark:bg-[#1b2c22]' => $result['is_valid'],
                        'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950' => ! $result['is_valid'],
                    ])>
                        <h2 class="text-[0.875rem] font-semibold uppercase tracking-wider text-ink-soft dark:text-[#b3ab9b]">Verification status</h2>
                        <div class="mt-3 flex items-start gap-3">
                            @if ($result['is_valid'])
                                <x-heroicon-o-shield-check class="h-9 w-9 shrink-0 text-forest-700 dark:text-forest-300" />
                            @else
                                <x-heroicon-o-shield-exclamation class="h-9 w-9 shrink-0 text-red-600 dark:text-red-300" />
                            @endif
                            <div>
                                <p @class([
                                    'font-display text-xl font-bold',
                                    'text-forest-800 dark:text-forest-200' => $result['is_valid'],
                                    'text-red-800 dark:text-red-200' => ! $result['is_valid'],
                                ])>{{ $result['status'] }}</p>
                                <p class="mt-1 text-[1.0625rem] text-ink-soft dark:text-[#b3ab9b]">
                                    @if ($result['is_valid'])
                                        We issued this receipt and it has not been withdrawn.
                                    @else
                                        We issued this receipt but it has since been withdrawn.
                                        @if ($result['void_reason']) Reason: {{ $result['void_reason'] }} @endif
                                    @endif
                                </p>
                            </div>
                        </div>

                        <dl class="mt-4 space-y-2 border-t border-black/5 pt-3 text-[1.0625rem] dark:border-white/10">
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-soft dark:text-[#b3ab9b]">Checked on</dt>
                                <dd class="text-right font-medium text-ink dark:text-[#e4ddcf]">{{ $result['checked_at']->isoFormat('D MMM YYYY, HH:mm') }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-soft dark:text-[#b3ab9b]">Checked by</dt>
                                <dd class="text-right font-medium text-ink dark:text-[#e4ddcf]">{{ $result['issuer'] }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-soft dark:text-[#b3ab9b]">Record integrity</dt>
                                <dd @class([
                                    'text-right font-medium',
                                    'text-forest-700 dark:text-forest-300' => $result['integrity_verified'],
                                    'text-red-700 dark:text-red-300' => ! $result['integrity_verified'],
                                ])>
                                    {{ $result['integrity_verified'] ? 'Integrity verified' : 'Integrity check failed' }}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                        <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">What this check covers</h2>
                        <ul class="mt-3 space-y-2 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
                            @foreach ([
                                'The receipt number exists in our records.',
                                'The supplier, issue date and amount shown here are the ones we hold.',
                                'The receipt has not been withdrawn by us.',
                            ] as $point)
                                <li class="flex gap-2">
                                    <x-heroicon-m-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-forest-600 dark:text-forest-400" />
                                    <span>{{ $point }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-3 text-[0.9375rem] leading-relaxed text-ink-soft dark:text-[#b3ab9b]">
                            It does not confirm that any money changed hands, or that goods were shipped or received.
                        </p>
                    </section>
                </aside>
            </div>
        @endif

        <section class="mt-8 rounded-2xl border border-sand-200 bg-white p-5 text-[1.0625rem] text-ink-soft dark:border-[#2c2a24] dark:bg-[#1f1d18] dark:text-[#8f887b] sm:p-7">
            <h2 class="font-display text-[1.0625rem] font-bold text-forest-950 dark:text-sand-100">Still unsure?</h2>
            <p class="mt-2">
                If a document does not verify, or the details here do not match what you were sent,
                <a href="{{ route('contact') }}" class="font-semibold text-forest-700 hover:underline dark:text-forest-300">contact our team</a>
                before acting on it.
            </p>
        </section>
    </div>
</x-layouts.app>
