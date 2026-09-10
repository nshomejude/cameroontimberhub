@component('mail::message')
# {{ __('notifications.inquiry_verification.heading') }}

{{ __('notifications.inquiry_verification.intro') }}

@component('mail::button', ['url' => $verifyUrl])
{{ __('notifications.inquiry_verification.action') }}
@endcomponent

{{ __('notifications.inquiry_verification.disclaimer') }}

{{ __('notifications.inquiry_verification.salutation') }}<br>
{{ config('app.name') }}
@endcomponent
