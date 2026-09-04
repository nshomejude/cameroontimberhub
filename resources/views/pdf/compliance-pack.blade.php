<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Compliance Evidence Pack — {{ $lot->lot_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; padding: 0; }
        .header { border-bottom: 2px solid #2f4f3a; padding-bottom: 10px; margin-bottom: 16px; }
        .header h1 { font-size: 18px; margin: 0 0 4px 0; color: #2f4f3a; }
        .header .meta { font-size: 10px; color: #555; }
        .section { margin-bottom: 16px; page-break-inside: avoid; }
        .section h2 { font-size: 13px; background: #f2efe8; padding: 5px 8px; margin: 0 0 8px 0; border-left: 3px solid #2f4f3a; }
        table.kv { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 3px 6px; vertical-align: top; border-bottom: 1px solid #eee; }
        table.kv td.label { width: 35%; color: #555; font-weight: bold; }
        table.list { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.list th { text-align: left; background: #f8f6f0; padding: 4px 6px; font-size: 10px; border-bottom: 1px solid #ccc; }
        table.list td { padding: 4px 6px; font-size: 10px; border-bottom: 1px solid #eee; }
        .empty { color: #888; font-style: italic; }
        .footer { margin-top: 24px; border-top: 1px solid #ccc; padding-top: 8px; font-size: 9px; color: #666; }
    </style>
</head>
<body>

    <div class="header">
        <h1>CTH Compliance Evidence Pack</h1>
        <div class="meta">
            Lot {{ $lot->lot_number }} &middot; Generated {{ $generated_at->format('d M Y H:i') }} UTC
        </div>
    </div>

    {{-- Supplier identity --}}
    <div class="section">
        <h2>Supplier Identity</h2>
        @if ($supplier)
            <table class="kv">
                <tr><td class="label">Legal name</td><td>{{ $supplier['legal_name'] ?? '—' }}</td></tr>
                <tr><td class="label">Registration number</td><td>{{ $supplier['registration_number'] ?? '—' }}</td></tr>
                <tr><td class="label">Country</td><td>{{ $supplier['country'] ?? '—' }}</td></tr>
            </table>
        @else
            <p class="empty">No supplier data available.</p>
        @endif
    </div>

    {{-- Lot details --}}
    <div class="section">
        <h2>Lot Details</h2>
        <table class="kv">
            <tr><td class="label">Lot number</td><td>{{ $lot->lot_number }}</td></tr>
            <tr><td class="label">Species</td><td>{{ $lot->species->common_name ?? '—' }}</td></tr>
            <tr><td class="label">Product form</td><td>{{ $lot->product_form ? ucwords(str_replace('_', ' ', $lot->product_form)) : '—' }}</td></tr>
            <tr><td class="label">Quantity</td><td>{{ $lot->quantity !== null ? number_format((float) $lot->quantity, 2) : '—' }} {{ $lot->unit }}</td></tr>
            <tr><td class="label">Volume</td><td>{{ $lot->volume_m3 !== null ? number_format((float) $lot->volume_m3, 3).' m³' : '—' }}</td></tr>
            <tr><td class="label">Status</td><td>{{ $lot->status instanceof \BackedEnum ? ucwords(str_replace('_', ' ', $lot->status->value)) : $lot->status }}</td></tr>
            <tr><td class="label">Legality evidence status</td><td>{{ ucwords(str_replace('_', ' ', (string) $lot->legality_evidence_status)) }}</td></tr>
            <tr><td class="label">Traceability status</td><td>{{ ucwords(str_replace('_', ' ', (string) $lot->traceability_status)) }}</td></tr>
        </table>
    </div>

    {{-- Origin information --}}
    <div class="section">
        <h2>Origin Information</h2>
        <table class="kv">
            <tr><td class="label">Country of origin</td><td>{{ $origin['country'] ?? '—' }}</td></tr>
            <tr><td class="label">Region</td><td>{{ $origin['region'] ?? '—' }}</td></tr>
            @if (empty($origin['region']) && !empty($origin['approximate_location']))
                <tr><td class="label">Approximate location</td><td>{{ $origin['approximate_location'] }}</td></tr>
            @endif
            <tr><td class="label">Forest source</td><td>{{ $origin['forest_source'] ?? '—' }}</td></tr>
            <tr><td class="label">Harvest block reference</td><td>{{ $origin['harvest_block_reference'] ?? '—' }}</td></tr>
            <tr>
                <td class="label">Harvest period</td>
                <td>
                    @if ($origin['harvest_period_start'] || $origin['harvest_period_end'])
                        {{ $origin['harvest_period_start']?->format('M Y') }}@if($origin['harvest_period_start'] && $origin['harvest_period_end']) – @endif{{ $origin['harvest_period_end']?->format('M Y') }}
                    @else
                        —
                    @endif
                </td>
            </tr>
        </table>
    </div>

    {{-- Applicable compliance checklist --}}
    <div class="section">
        <h2>Applicable Compliance Checklist</h2>
        @if ($compliance_rules->isNotEmpty())
            <table class="list">
                <thead>
                    <tr><th>Framework</th><th>Market / Country</th><th>Required evidence</th></tr>
                </thead>
                <tbody>
                    @foreach ($compliance_rules as $rule)
                        <tr>
                            <td>{{ $rule->regulatory_framework }}</td>
                            <td>{{ $rule->market ?? $rule->country_code ?? 'All markets' }}</td>
                            <td>{{ !empty($rule->required_evidence) ? implode(', ', $rule->required_evidence) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty">No applicable compliance rules on record for this lot.</p>
        @endif
    </div>

    {{-- Traceability event ledger --}}
    <div class="section">
        <h2>Traceability Event Ledger</h2>
        @if ($lot_events->isNotEmpty())
            <table class="list">
                <thead>
                    <tr><th>Event</th><th>Date</th><th>Location</th></tr>
                </thead>
                <tbody>
                    @foreach ($lot_events as $event)
                        <tr>
                            <td>{{ $event->event_type instanceof \BackedEnum ? ucwords(str_replace('_', ' ', $event->event_type->value)) : ucwords(str_replace('_', ' ', (string) $event->event_type)) }}</td>
                            <td>{{ $event->occurred_at?->format('d M Y') ?? '—' }}</td>
                            <td>{{ $event->location ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty">No traceability events available for this lot.</p>
        @endif
    </div>

    {{-- Processing records --}}
    <div class="section">
        <h2>Processing Records</h2>
        @if ($transformations->isNotEmpty())
            <table class="list">
                <thead>
                    <tr><th>Type</th><th>Date</th><th>Input volume</th><th>Output volume</th></tr>
                </thead>
                <tbody>
                    @foreach ($transformations as $transformation)
                        <tr>
                            <td>{{ ucwords(str_replace('_', ' ', (string) $transformation->transformation_type)) }}</td>
                            <td>{{ $transformation->processed_at?->format('d M Y') ?? '—' }}</td>
                            <td>{{ $transformation->input_volume_m3 !== null ? number_format((float) $transformation->input_volume_m3, 3).' m³' : '—' }}</td>
                            <td>{{ $transformation->output_volume_m3 !== null ? number_format((float) $transformation->output_volume_m3, 3).' m³' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty">No processing records available for this lot.</p>
        @endif
    </div>

    {{-- Inspection report summary --}}
    <div class="section">
        <h2>Inspection Report Summary</h2>
        @if ($inspection)
            <table class="kv">
                <tr><td class="label">Type</td><td>{{ $inspection['inspection_type'] ?? '—' }}</td></tr>
                <tr><td class="label">Performed</td><td>{{ $inspection['performed_at']?->format('d M Y') ?? '—' }}</td></tr>
                <tr><td class="label">Finalised</td><td>{{ $inspection['finalised_at']?->format('d M Y') ?? '—' }}</td></tr>
                <tr><td class="label">Result</td><td>{{ $inspection['result'] ? ucwords((string) $inspection['result']) : '—' }}</td></tr>
                <tr><td class="label">Observed quantity</td><td>{{ $inspection['observed_quantity'] !== null ? number_format((float) $inspection['observed_quantity'], 2) : '—' }}</td></tr>
                <tr><td class="label">Digital signature</td><td style="font-size:8px;word-break:break-all;">{{ $inspection['digital_signature'] ?? '—' }}</td></tr>
            </table>
        @else
            <p class="empty">No finalised inspection report available for this lot.</p>
        @endif
    </div>

    <div class="footer">
        This pack does not constitute governmental approval or certification. It is a compiled evidence summary generated by Cameroon Timber Hub based on information available on the platform at the time of generation.
        <br>Audit timestamp: {{ $generated_at->toIso8601String() }}
    </div>

</body>
</html>
