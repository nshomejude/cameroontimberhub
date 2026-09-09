<x-filament-panels::page>
    @php
        $casesByStatus = $this->getOpenCasesByStatus();
        $rules = $this->getRecentlyUpdatedRules();
        $highRiskCompanies = $this->getHighRiskCompanies();
        $revocationRequests = $this->getPendingRevocationRequests();
    @endphp

    <div class="space-y-6">
        {{-- Open cases by status --}}
        <x-filament::section>
            <x-slot name="heading">Open compliance cases</x-slot>

            @if($casesByStatus->isEmpty())
                <p class="text-sm text-ink-soft">No open compliance cases right now.</p>
            @else
                <div class="flex flex-wrap gap-3">
                    @foreach($casesByStatus as $status => $count)
                        <div class="rounded-lg border border-sand-200 px-4 py-3">
                            <p class="text-2xl font-bold text-ink">{{ $count }}</p>
                            <p class="text-xs text-ink-soft">{{ $this->statusLabel($status) }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- High-risk companies --}}
        <x-filament::section>
            <x-slot name="heading">Companies flagged high risk</x-slot>

            @if($highRiskCompanies->isEmpty())
                <p class="text-sm text-ink-soft">No company currently sits in the high or critical risk band.</p>
            @else
                <div class="divide-y divide-sand-200 -my-6">
                    @foreach($highRiskCompanies as $assessment)
                        <div class="flex items-center justify-between gap-4 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-ink">{{ $assessment->company?->name ?? 'Unknown company' }}</p>
                                <p class="mt-0.5 text-xs text-ink-soft">Composite score {{ $assessment->composite_score }}</p>
                            </div>
                            <x-filament::badge color="danger">
                                {{ ucwords(str_replace('_', ' ', $assessment->risk_band)) }}
                            </x-filament::badge>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- Recently updated rules --}}
        <x-filament::section>
            <x-slot name="heading">Recently updated compliance rules</x-slot>

            @if($rules->isEmpty())
                <p class="text-sm text-ink-soft">No active compliance rules on file yet.</p>
            @else
                <div class="divide-y divide-sand-200 -my-6">
                    @foreach($rules as $rule)
                        <div class="flex items-center justify-between gap-4 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-ink">{{ $rule->regulatory_framework }}</p>
                                <p class="mt-0.5 text-xs text-ink-soft">
                                    {{ $rule->regulatorySource?->instrument_name ?? 'Source not linked' }}
                                    @if($rule->country_code) &middot; {{ $rule->country_code }} @endif
                                </p>
                            </div>
                            <span class="text-xs text-ink-soft">{{ $rule->updated_at?->diffForHumans() }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- Pending verification revocation approvals --}}
        <x-filament::section>
            <x-slot name="heading">Pending revocation approvals</x-slot>

            @if($revocationRequests->isEmpty())
                <p class="text-sm text-ink-soft">No verification revocation requests are awaiting approval.</p>
            @else
                <div class="divide-y divide-sand-200 -my-6">
                    @foreach($revocationRequests as $request)
                        <div class="flex items-center justify-between gap-4 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-ink">{{ $request->company?->name ?? 'Unknown company' }}</p>
                                <p class="mt-0.5 text-xs text-ink-soft">
                                    Requested by {{ $request->requestedBy?->name ?? 'Unknown' }}
                                </p>
                            </div>
                            <x-filament::badge color="warning">Pending</x-filament::badge>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
