<x-layouts.app
    :title="'Verify listing — '.$product->name"
    description="Verify a Cameroon Timber Hub marketplace listing."
    noindex>
    <div class="mx-auto max-w-2xl px-4 py-10 sm:py-16">
        <p class="eyebrow">Listing verification</p>
        <h1 class="mt-3 text-2xl font-semibold text-ink dark:text-[#e4ddcf]">{{ $product->name }}</h1>
        <p class="mt-1 font-mono text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">{{ $product->public_id }}</p>

        <div class="mt-8 rounded-2xl border border-sand-200 bg-white p-6 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
            <dl class="grid grid-cols-1 gap-4 text-[0.9375rem] sm:grid-cols-2">
                <div>
                    <dt class="text-ink-soft dark:text-[#8f887b]">Species</dt>
                    <dd class="font-medium text-ink dark:text-[#e4ddcf]">
                        @if ($species)
                            <a href="{{ route('species.show', $species->slug) }}" class="hover:underline">{{ $species->common_name }}</a>
                        @else
                            Not catalogued
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-ink-soft dark:text-[#8f887b]">Verified supplier</dt>
                    <dd class="font-medium text-ink dark:text-[#e4ddcf]">
                        @if ($company)
                            <a href="{{ route('companies.show', $company->slug) }}" class="hover:underline">{{ $company->legal_name }}</a>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                @if ($stageLabel)
                    <div>
                        <dt class="text-ink-soft dark:text-[#8f887b]">Verification stage</dt>
                        <dd class="font-medium text-ink dark:text-[#e4ddcf]">
                            {{ $stageLabel }}{{ $isVerified ? '' : ' (in progress)' }}
                        </dd>
                    </div>
                @endif
            </dl>

            @if ($documents->isNotEmpty() || $certificates->isNotEmpty())
                <div class="mt-6 border-t border-sand-200 pt-4 dark:border-[#2c2a24]">
                    <p class="text-[0.9375rem] font-semibold text-ink dark:text-[#e4ddcf]">Documents</p>
                    <ul class="mt-2 space-y-1 text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">
                        @foreach ($documents as $document)
                            <li>{{ $document->typeLabel() }} — {{ ['expired' => 'expired', 'verified' => 'verified', 'pending' => 'pending review'][$document->publicStatus()] }}</li>
                        @endforeach
                        @foreach ($certificates as $certificate)
                            <li>{{ $certificate->certificate_number ?? 'Certificate' }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="mt-6 rounded-2xl border border-sand-200 bg-white p-6 text-center dark:border-[#2c2a24] dark:bg-[#1f1d18]">
            <p class="text-[0.9375rem] font-semibold text-ink dark:text-[#e4ddcf]">Scan to reopen this page</p>
            <div class="mx-auto mt-3 h-40 w-40">{!! $qrSvg !!}</div>
            <p class="mt-2 break-all font-mono text-[0.8125rem] text-ink-soft dark:text-[#8f887b]">{{ $verificationUrl }}</p>
        </div>

        <p class="mt-6 text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">
            Something look wrong?
            <a href="mailto:trust@cameroontimberhub.com?subject={{ rawurlencode('Concern about listing '.$product->public_id) }}" class="font-medium text-forest-700 hover:underline dark:text-forest-400">Report a concern</a>.
        </p>
    </div>
</x-layouts.app>
