@component('mail::message')
# You have a new quote

**{{ $quote->company->name }}** has responded to your request **{{ $quote->rfq->reference_code }}**.

- **Quote reference:** {{ $quote->reference_code }}
- **Total:** {{ $quote->money($quote->total_amount) }}
@if ($quote->lead_time_days)
- **Lead time:** {{ $quote->lead_time_days }} days
@endif
@if ($quote->valid_until)
- **Valid until:** {{ $quote->valid_until->isoFormat('D MMM YYYY') }}
@endif

@component('mail::button', ['url' => $responsesUrl])
Review your quotes
@endcomponent

This link is personal to your request — please do not forward it.

Receiving a quote does not constitute a contract. Buyers should conduct final due diligence before any transaction.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
