<x-layouts.app title="Verify certificate" description="Verify a TimberHub certificate." noindex>
    <div class="mx-auto max-w-2xl px-4 py-10 sm:py-16">
        <h1 class="text-2xl font-semibold text-ink dark:text-[#e4ddcf]">Verify a certificate</h1>
        <p class="mt-2 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
            Scan the QR code on a TimberHub certificate, or open its verification link directly.
        </p>

        @if ($searched && ! $result)
            <div class="mt-8 rounded-2xl border border-sand-200 bg-white p-6 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                <p class="text-[1.0625rem] font-medium text-red-700 dark:text-red-400">Certificate not found.</p>
                <p class="mt-1 text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">The link may be mistyped, or the certificate may no longer be current.</p>
            </div>
        @endif

        @if ($result)
            <div class="mt-8 rounded-2xl border border-sand-200 bg-white p-6 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                <div class="flex items-center justify-between gap-4">
                    <p class="text-[1.0625rem] font-semibold text-ink dark:text-[#e4ddcf]">{{ $result['certificate_number'] }}</p>
                    <span class="rounded-full px-3 py-1 text-[0.8125rem] font-semibold {{ $result['is_currently_valid'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                        {{ $result['status'] }}
                    </span>
                </div>

                @unless ($result['is_current_version'])
                    <p class="mt-3 rounded-xl bg-amber-50 px-4 py-3 text-[0.9375rem] text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                        This is version {{ $result['version'] }} of this certificate, which is no longer the current version.
                    </p>
                @endunless

                <dl class="mt-4 grid grid-cols-1 gap-3 text-[0.9375rem] sm:grid-cols-2">
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Signature</dt><dd class="font-medium">{{ $result['signature_valid'] ? 'Valid' : 'Could not verify' }}</dd></div>
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Hash</dt><dd class="font-medium">{{ $result['hash_valid'] ? 'Valid' : 'Missing' }}</dd></div>
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Version</dt><dd class="font-medium">v{{ $result['version'] }} {{ $result['is_current_version'] ? '(current)' : '(not current)' }}</dd></div>
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Fingerprint</dt><dd class="font-mono text-[0.8125rem]">{{ $result['fingerprint'] ?? '—' }}</dd></div>
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Evidence</dt><dd class="font-medium">{{ $result['evidence_status'] }}</dd></div>
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Geospatial record</dt><dd class="font-medium">{{ $result['geospatial_status'] }}</dd></div>
                </dl>

                @if (($result['data']['product'] ?? null))
                    <p class="mt-4 border-t border-sand-200 pt-4 text-[0.9375rem] text-ink dark:border-[#2c2a24] dark:text-[#e4ddcf]">
                        Product: {{ $result['data']['product'] }}
                    </p>
                @endif

                <p class="mt-4 text-[0.8125rem] text-ink-soft dark:text-[#8f887b]">Checked {{ $result['checked_at']->diffForHumans() }}.</p>
            </div>
        @endif
    </div>
</x-layouts.app>
