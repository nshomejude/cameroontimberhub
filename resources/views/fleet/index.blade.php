@php
    // Document-expiry status for the registry table, computed straight off
    // each Document row -- there is no bespoke expiry system here, only the
    // shared Document store (see App\Models\Document::isExpired()).
    $documentStatus = function ($documents) {
        if ($documents->isEmpty()) {
            return ['label' => 'No documents', 'class' => 'bg-sand-100 text-ink-soft'];
        }

        $expired = $documents->contains(fn ($d) => $d->isExpired());
        if ($expired) {
            return ['label' => 'Expired document', 'class' => 'bg-red-100 text-red-800'];
        }

        $expiringSoon = $documents->contains(fn ($d) => $d->expires_at !== null && ! $d->isExpired() && $d->expires_at->diffInDays(now()) <= 30);
        if ($expiringSoon) {
            return ['label' => 'Expiring soon', 'class' => 'bg-amber-100 text-amber-800'];
        }

        return ['label' => 'Documents ok', 'class' => 'bg-forest-100 text-forest-800'];
    };
@endphp

<x-layouts.app title="Fleet Registry" noindex>
    <div class="mx-auto max-w-[1200px] px-4 py-8 lg:px-6">
        <h1 class="font-display text-2xl font-bold text-forest-950">Fleet Registry</h1>
        <p class="mt-1 text-sm text-ink-soft">Vehicles and drivers for {{ $company->name }}, with compliance document status.</p>

        <section aria-label="Vehicles" class="mt-8">
            <h2 class="text-lg font-semibold text-forest-950">Vehicles</h2>
            @if ($vehicles->isEmpty())
                <p class="mt-2 text-sm text-ink-soft">No vehicles registered yet.</p>
            @else
                <div class="mt-3 overflow-x-auto rounded-xl border border-sand-200">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-sand-50 text-xs uppercase text-ink-soft">
                            <tr>
                                <th class="px-4 py-3">Registration</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Capacity (t)</th>
                                <th class="px-4 py-3">Documents</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sand-100">
                            @foreach ($vehicles as $vehicle)
                                @php $status = $documentStatus($vehicle->documents); @endphp
                                <tr>
                                    <td class="px-4 py-3 font-medium text-ink">{{ $vehicle->registration_number }}</td>
                                    <td class="px-4 py-3">{{ ucfirst($vehicle->type) }}</td>
                                    <td class="px-4 py-3">{{ $vehicle->capacity_tonnes ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $status['class'] }}">{{ $status['label'] }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section aria-label="Drivers" class="mt-8">
            <h2 class="text-lg font-semibold text-forest-950">Drivers</h2>
            @if ($drivers->isEmpty())
                <p class="mt-2 text-sm text-ink-soft">No drivers registered yet.</p>
            @else
                <div class="mt-3 overflow-x-auto rounded-xl border border-sand-200">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-sand-50 text-xs uppercase text-ink-soft">
                            <tr>
                                <th class="px-4 py-3">Name</th>
                                <th class="px-4 py-3">License #</th>
                                <th class="px-4 py-3">Phone</th>
                                <th class="px-4 py-3">Documents</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sand-100">
                            @foreach ($drivers as $driver)
                                @php $status = $documentStatus($driver->documents); @endphp
                                <tr>
                                    <td class="px-4 py-3 font-medium text-ink">{{ $driver->name }}</td>
                                    <td class="px-4 py-3">{{ $driver->license_number }}</td>
                                    <td class="px-4 py-3">{{ $driver->phone ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $status['class'] }}">{{ $status['label'] }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-layouts.app>
