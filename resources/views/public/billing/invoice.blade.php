@php
    /** @var \App\Models\Invoice $invoice */
    $pdf = $pdf ?? false;
    $bt = $invoice->bill_to ?? [];
    $bf = $invoice->bill_from ?? [];
    $money = fn ($a) => $invoice->currency->value.' '.number_format((float) $a, 2);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('messages.billing.invoice_title') }} {{ $invoice->invoice_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #1f1d18; margin: 0; padding: 0; background: #f4f2ec; font-size: 13px; }
        .sheet { max-width: 820px; margin: 24px auto; background: #fff; padding: 40px; border: 1px solid #e2ddd0; }
        h1 { font-size: 26px; margin: 0; color: #1f3d2b; letter-spacing: .04em; }
        .muted { color: #6b6559; }
        .row { display: flex; justify-content: space-between; gap: 24px; }
        .col { width: 48%; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        th, td { text-align: left; padding: 8px 10px; }
        thead th { background: #1f3d2b; color: #fff; }
        tbody td { border-bottom: 1px solid #e2ddd0; }
        tfoot td { padding: 6px 10px; }
        .tot { text-align: right; }
        .stamp { display: inline-block; margin-top: 16px; border: 2px solid #1f3d2b; color: #1f3d2b; padding: 4px 14px; font-weight: bold; letter-spacing: .08em; transform: rotate(-3deg); }
        .void { border-color: #b91c1c; color: #b91c1c; }
        .btn { display: inline-block; margin: 16px 0; padding: 8px 18px; background: #1f3d2b; color: #fff; text-decoration: none; border-radius: 999px; border: 0; cursor: pointer; }
        @media print { .noprint { display: none; } body { background: #fff; } .sheet { border: 0; margin: 0; } }
    </style>
</head>
<body>
<div class="sheet">
    @unless ($pdf)
        <div class="noprint">
            <button class="btn" onclick="window.print()">{{ __('messages.billing.invoice_print') }}</button>
            <a class="btn" href="{{ route('billing.invoices.show', [$invoice, 'format' => 'pdf']) }}">{{ __('messages.billing.invoice_download_pdf') }}</a>
        </div>
    @endunless

    <div class="row">
        <div class="col">
            <strong>{{ $bf['organisation'] ?? config('app.name') }}</strong><br>
            @foreach (($bf['address_lines'] ?? []) as $l){{ $l }}<br>@endforeach
            @if (!empty($bf['email'])){{ $bf['email'] }}<br>@endif
            @if (!empty($bf['phone'])){{ $bf['phone'] }}<br>@endif
            @if (!empty($bf['website'])){{ $bf['website'] }}@endif
        </div>
        <div class="col" style="text-align:right">
            <h1>{{ __('messages.billing.invoice_title') }}</h1>
            <p class="muted">
                {{ __('messages.billing.invoice_number') }}: <strong>{{ $invoice->invoice_number }}</strong><br>
                {{ __('messages.billing.invoice_issued') }}: {{ $invoice->issued_at->isoFormat('D MMM YYYY') }}<br>
                {{ __('messages.billing.invoice_status') }}: {{ $invoice->status->label() }}
            </p>
        </div>
    </div>

    <div class="row" style="margin-top:18px">
        <div class="col">
            <div class="muted">{{ __('messages.billing.invoice_bill_to') }}</div>
            <strong>{{ $bt['legal_name'] ?? $bt['trade_name'] ?? '—' }}</strong><br>
            @if (!empty($bt['address_line'])){{ $bt['address_line'] }}<br>@endif
            {{ collect([$bt['city'] ?? null, $bt['region'] ?? null, $bt['country_code'] ?? null])->filter()->implode(', ') }}
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>{{ __('messages.billing.invoice_description') }}</th>
                <th class="tot">{{ __('messages.billing.invoice_qty') }}</th>
                <th class="tot">{{ __('messages.billing.invoice_unit') }}</th>
                <th class="tot">{{ __('messages.billing.invoice_amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="tot">{{ $line->quantity }}</td>
                    <td class="tot">{{ $money($line->unit_amount) }}</td>
                    <td class="tot">{{ $money($line->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="3" class="tot muted">{{ __('messages.billing.tax_subtotal') }}</td><td class="tot">{{ $money($invoice->subtotal_amount) }}</td></tr>
            @if (((float) $invoice->tax_amount) > 0)
                <tr><td colspan="3" class="tot muted">{{ __('messages.billing.tax_line', ['label' => $invoice->tax_label, 'rate' => rtrim(rtrim(number_format(((float) $invoice->tax_rate) * 100, 4), '0'), '.')]) }}</td><td class="tot">{{ $money($invoice->tax_amount) }}</td></tr>
            @endif
            <tr><td colspan="3" class="tot"><strong>{{ __('messages.billing.tax_total') }}</strong></td><td class="tot"><strong>{{ $money($invoice->total_amount) }}</strong></td></tr>
        </tfoot>
    </table>

    <div class="stamp {{ $invoice->isVoid() ? 'void' : '' }}">
        {{ $invoice->isVoid() ? __('messages.billing.invoice_void_stamp') : __('messages.billing.invoice_paid_stamp') }}
    </div>
    @if ($invoice->payment_id)
        <p class="muted">{{ __('messages.billing.invoice_payment_ref') }}: #{{ $invoice->payment_id }}</p>
    @endif

    @if ($invoice->creditNotes->isNotEmpty())
        <p class="muted">
            {{ __('messages.billing.invoice_credit_notes') }}:
            @foreach ($invoice->creditNotes as $cn)
                <a href="{{ route('billing.credit-notes.show', $cn) }}">{{ $cn->credit_note_number }}</a>@if (!$loop->last), @endif
            @endforeach
        </p>
    @endif

    <p class="muted" style="margin-top:24px; font-size:11px">{{ __('messages.billing.invoice_footer') }}</p>
</div>
</body>
</html>
