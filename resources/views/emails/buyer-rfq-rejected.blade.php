@component('mail::message')
# {{ __('notifications.buyer_rfq_rejected.heading') }}

{{ __('notifications.buyer_rfq_rejected.intro', ['reference' => $rfq->reference_code]) }}

{{ __('notifications.buyer_rfq_rejected.reason', ['reason' => $reason]) }}

{{ __('notifications.buyer_rfq_rejected.next') }}

{{ __('notifications.buyer_rfq_rejected.salutation') }}<br>
{{ config('app.name') }}
@endcomponent
