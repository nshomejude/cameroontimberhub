@component('mail::message')
# Confirm your inquiry

Please confirm your email so your message can be delivered to the exporter.

@component('mail::button', ['url' => $verifyUrl])
Confirm inquiry
@endcomponent

Buyers should conduct final due diligence before any transaction.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
