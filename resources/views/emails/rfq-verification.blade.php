@component('mail::message')
# Confirm your request

Thanks for your request **{{ $rfq->reference_code }}**. Please confirm your email so we can route it to verified Cameroonian timber exporters.

@component('mail::button', ['url' => $verifyUrl])
Confirm request
@endcomponent

Submitting a request does not constitute a contract. Buyers should conduct final due diligence before any transaction.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
