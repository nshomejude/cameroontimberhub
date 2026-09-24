@php
    // Certificate print/verification view -- Rings 1+2 only (Ring 3 physical
    // security layers are explicitly deferred to gap-plan 0.8b). Follows the
    // house window.print() pattern from
    // resources/views/public/orders/receipt.blade.php: no PDF library, hand-
    // styled Tailwind + .article-prose, never Tailwind's `prose` classes
    // (this project has no typography plugin -- see resources/css/app.css).
    $geo = $certificate->geospatial_data;
@endphp

<x-layouts.app :title="'Certificate '.$certificate->certificate_number" description="TimberHub certificate." noindex>
    <div class="certificate-page mx-auto max-w-5xl px-4 py-8 sm:py-12">

        <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
            <p class="text-[1.0625rem] font-medium text-ink dark:text-[#e4ddcf]">Certificate {{ $certificate->certificate_number }}</p>
            <button type="button" onclick="window.print()"
                    class="inline-flex items-center gap-2 rounded-full bg-forest-700 px-5 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800">
                <x-heroicon-m-printer class="h-4 w-4" /> Print certificate
            </button>
        </div>

        <article class="certificate-sheet relative overflow-hidden mt-4 rounded-2xl border border-sand-200 bg-white p-5 text-ink dark:border-[#2c2a24] dark:bg-[#1f1d18] dark:text-[#e4ddcf] sm:p-8">

            {{-- Ring 3 variable security background: a diagonal microtext
                 pattern seeded from data_hash (see CertificateWatermarkService).
                 Subtle by design -- this is NOT the "VOID" stamp below. --}}
            <div class="certificate-watermark pointer-events-none absolute inset-0" style="{{ $watermarkStyle }}" aria-hidden="true"></div>

            @if ($showVoidStamp)
                {{-- Unmissable, distinct from the subtle background pattern:
                     shown only for Superseded/Revoked certificates so a
                     printed copy of a non-live document can never be
                     mistaken for a currently-valid one. --}}
                <div class="certificate-void-stamp pointer-events-none absolute inset-0 flex items-center justify-center" aria-hidden="true">
                    <span class="select-none text-[7rem] font-black uppercase tracking-widest text-red-600/25 [transform:rotate(-28deg)] sm:text-[9rem]">{{ $voidLabel }}</span>
                </div>
            @endif

            <div class="relative z-10">

            {{-- Identity block --}}
            <header class="grid gap-6 border-b border-sand-200 pb-6 dark:border-[#2c2a24] md:grid-cols-[1.4fr_1fr]">
                <div>
                    <img src="/brand/logo-600.png" alt="Cameroon Timber Hub" class="h-12 w-auto" width="600" height="200">
                    <p class="mt-3 text-[1.0625rem] font-semibold">{{ $certificate->certificate_number }}</p>
                    <p class="text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">Version {{ $certificate->version }} — {{ $certificate->status->label() }}</p>
                </div>
                <div class="flex flex-col items-end gap-2">
                    <img src="{{ $qrDataUri }}" alt="Scan to verify this certificate" width="140" height="140">
                    <p class="text-[0.8125rem] text-ink-soft dark:text-[#8f887b] break-all text-right">{{ $verificationUrl }}</p>
                    <img src="{{ $barcodeDataUri }}" alt="Barcode: {{ $certificate->certificate_number }}" class="mt-2 h-12 bg-white p-1">
                </div>
            </header>

            {{-- Human-readable data (product/origin/production/supplier) --}}
            <section class="article-prose mt-6">
                <h2>Certified product</h2>
                <p>{{ $certificate->data['product'] ?? '—' }}</p>
                @if (! empty($certificate->data['origin']))
                    <h3>Origin</h3>
                    <p>{{ $certificate->data['origin']['country'] ?? '—' }}@if (! empty($certificate->data['origin']['region'])), {{ $certificate->data['origin']['region'] }}@endif</p>
                @endif
                @if ($certificate->certified_quantity)
                    <h3>Certified quantity</h3>
                    <p>{{ number_format((float) $certificate->certified_quantity, 3) }} {{ $certificate->quantity_unit }}</p>
                @endif
            </section>

            {{-- Cryptographic fingerprint --}}
            <section class="mt-6 rounded-xl border border-sand-200 p-4 dark:border-[#2c2a24]">
                <p class="text-[0.8125rem] font-semibold uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Cryptographic fingerprint</p>
                <p class="mt-1 font-mono text-[0.9375rem]">{{ $certificate->fingerprint() ?? 'Not yet signed' }}</p>
            </section>

            {{-- Authenticity & integrity panel --}}
            <section class="mt-6 rounded-xl border border-sand-200 p-4 dark:border-[#2c2a24]">
                <p class="text-[0.8125rem] font-semibold uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Authenticity &amp; integrity</p>
                <dl class="mt-2 grid grid-cols-2 gap-2 text-[0.9375rem]">
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Signing key</dt><dd>{{ $certificate->key_id ?? '—' }}</dd></div>
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Algorithm</dt><dd>{{ $certificate->algorithm ?? '—' }}</dd></div>
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Signed at</dt><dd>{{ $certificate->signed_at?->toDayDateTimeString() ?? '—' }}</dd></div>
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Data hash</dt><dd class="font-mono text-[0.75rem] break-all">{{ $certificate->data_hash ?? '—' }}</dd></div>
                </dl>
            </section>

            {{-- Geospatial panel: text-only lat/long, honestly. No map image is
                 rendered because no maps provider or API key is configured in
                 this codebase; an invented map picture would be worse than
                 none. Coordinates are stored as decimal strings (see
                 CertificateGeoService) and printed verbatim, so the six
                 decimal places EUDR requires survive to the printed page. --}}
            @if ($geo)
                <section class="mt-6 rounded-xl border border-sand-200 p-4 dark:border-[#2c2a24]">
                    <p class="text-[0.8125rem] font-semibold uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Geospatial record</p>
                    <p class="mt-1 text-[0.9375rem]">
                        @if ($geo['type'] === 'Point')
                            Latitude {{ $geo['coordinates'][1] }}, Longitude {{ $geo['coordinates'][0] }}
                        @else
                            Plot boundary polygon ({{ count($geo['coordinates'][0] ?? []) - 1 }} vertices) — see the digital record for full coordinates.
                        @endif
                    </p>
                    <p class="mt-1 font-mono text-[0.75rem] break-all text-ink-soft dark:text-[#8f887b]">Geospatial hash: {{ $certificate->geospatial_hash ?? '—' }}</p>
                </section>
            @endif

            {{-- Evidence panel --}}
            <section class="mt-6 rounded-xl border border-sand-200 p-4 dark:border-[#2c2a24]">
                <p class="text-[0.8125rem] font-semibold uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Evidence</p>
                <p class="mt-1 font-mono text-[0.75rem] break-all">{{ $certificate->evidence_manifest_hash ?? 'No evidence manifest recorded' }}</p>
            </section>

            {{-- Issuance block --}}
            <section class="mt-6 text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">
                <p>Issued {{ $certificate->issued_at?->toDayDateTimeString() ?? '—' }} by {{ config('app.name') }}.</p>
            </section>

            {{-- Mandatory legal disclaimer --}}
            <footer class="mt-8 border-t border-sand-200 pt-6 text-[0.8125rem] text-ink-soft dark:border-[#2c2a24] dark:text-[#8f887b]">
                <p>This TimberHub certificate is a digitally verifiable record issued by Cameroon Timber Hub. It is not an EU-issued EUDR certificate and does not itself constitute regulatory clearance. Verify its current status at {{ $verificationUrl }}.</p>
            </footer>

            </div>
        </article>
    </div>
</x-layouts.app>
