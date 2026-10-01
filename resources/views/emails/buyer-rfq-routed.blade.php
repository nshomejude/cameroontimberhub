@component('mail::message')
# {{ __('notifications.buyer_rfq_routed.heading') }}

{{ __('notifications.buyer_rfq_routed.intro', ['reference' => $rfq->reference_code, 'count' => $count]) }}

{{ __('notifications.buyer_rfq_routed.next') }}

@component('mail::button', ['url' => $responsesUrl])
{{ __('notifications.buyer_rfq_routed.action') }}
@endcomponent

{{ __('notifications.buyer_rfq_routed.salutation') }}<br>
{{ config('app.name') }}
@endcomponent
