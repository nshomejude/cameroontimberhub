@component('mail::message')
# {{ __('notifications.rfq_verification.heading') }}

{{ __('notifications.rfq_verification.intro', ['reference' => $rfq->reference_code]) }}

@component('mail::button', ['url' => $verifyUrl])
{{ __('notifications.rfq_verification.action') }}
@endcomponent

{{ __('notifications.rfq_verification.disclaimer') }}

{{ __('notifications.rfq_verification.salutation') }}<br>
{{ config('app.name') }}
@endcomponent
