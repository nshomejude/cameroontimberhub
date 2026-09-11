@php
    /** @var \App\Models\CreditNote $creditNote */
    $pdf = $pdf ?? false;
    $money = fn ($a) => $creditNote->currency->value.' '.number_format((float) $a, 2);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('messages.billing.credit_note_title') }} {{ $creditNote->credit_note_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #1f1d18; margin: 0; padding: 0; background: #f4f2ec; font-size: 13px; }
        .sheet { max-width: 820px; margin: 24px auto; background: #fff; padding: 40px; border: 1px solid #e2ddd0; }
        h1 { font-size: 26px; margin: 0; color: #7c2d12; letter-spacing: .04em; }
        .muted { color: #6b6559; }
        .row { display: flex; justify-content: space-between; gap: 24px; }
        .col { width: 48%; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        th, td { text-align: left; padding: 8px 10px; }
        thead th { background: #7c2d12; color: #fff; }
        tbody td { border-bottom: 1px solid #e2ddd0; }
        tfoot td { padding: 6px 10px; }
        .tot { text-align: right; }
        .btn { display: inline-block; margin: 16px 0; padding: 8px 18px; background: #1f3d2b; color: #fff; text-decoration: none; border-radius: 999px; border: 0; cursor: pointer; }
        @media print { .noprint { display: none; } body { background: #fff; } .sheet { border: 0; margin: 0; } }
    </style>
</head>
<body>
<div class="sheet">
    @unless ($pdf)
        <div class="noprint">
            <button class="btn" onclick="window.print()">{{ __('messages.billing.invoice_print') }}</button>
            <a class="btn" href="{{ route('billing.credit-notes.show', [$creditNote, 'format' => 'pdf']) }}">{{ __('messages.billing.invoice_download_pdf') }}</a>
        </div>
    @endunless

    <div class="row">
        <div class="col">
            <strong>{{ config('contact.organisation', config('app.name')) }}</strong>
        </div>
        <div class="col" style="text-align:right">
            <h1>{{ __('messages.billing.credit_note_title') }}</h1>
            <p class="muted">
                {{ __('messages.billing.credit_note_number') }}: <strong>{{ $creditNote->credit_note_number }}</strong><br>
                {{ __('messages.billing.invoice_issued') }}: {{ $creditNote->issued_at->isoFormat('D MMM YYYY') }}<br>
                {{ __('messages.billing.credit_note_against') }}:
                <a href="{{ route('billing.invoices.show', $creditNote->invoice_id) }}">{{ $creditNote->invoice->invoice_number }}</a><br>
                {{ __('messages.billing.invoice_status') }}: {{ $creditNote->status->label() }}
            </p>
        </div>
    </div>

    <div class="row" style="margin-top:18px">
        <div class="col">
            <div class="muted">{{ __('messages.billing.credit_note_reason') }}</div>
            {{ $creditNote->reason }}
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
            @foreach ($creditNote->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="tot">{{ $line->quantity }}</td>
                    <td class="tot">{{ $money($line->unit_amount) }}</td>
                    <td class="tot">{{ $money($line->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="3" class="tot muted">{{ __('messages.billing.tax_subtotal') }}</td><td class="tot">{{ $money($creditNote->subtotal_amount) }}</td></tr>
            @if (((float) $creditNote->tax_amount) > 0)
                <tr><td colspan="3" class="tot muted">{{ __('messages.billing.tax_total') }}</td><td class="tot">{{ $money($creditNote->tax_amount) }}</td></tr>
            @endif
            <tr><td colspan="3" class="tot"><strong>{{ __('messages.billing.credit_note_total') }}</strong></td><td class="tot"><strong>{{ $money($creditNote->total_amount) }}</strong></td></tr>
        </tfoot>
    </table>

    <p class="muted" style="margin-top:24px; font-size:11px">{{ __('messages.billing.credit_note_footer') }}</p>
</div>
</body>
</html>
