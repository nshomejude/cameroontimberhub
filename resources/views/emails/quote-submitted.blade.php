@component('mail::message')
# {{ __('notifications.quote_submitted.heading') }}

{{ __('notifications.quote_submitted.intro', ['company' => $quote->company->name, 'reference' => $quote->rfq->reference_code]) }}

- **{{ __('notifications.quote_submitted.quote_reference') }}** {{ $quote->reference_code }}
- **{{ __('notifications.quote_submitted.total') }}** {{ $quote->money($quote->total_amount) }}
@if ($quote->lead_time_days)
- **{{ __('notifications.quote_submitted.lead_time') }}** {{ __('notifications.quote_submitted.lead_time_value', ['days' => $quote->lead_time_days]) }}
@endif
@if ($quote->valid_until)
- **{{ __('notifications.quote_submitted.valid_until') }}** {{ $quote->valid_until->isoFormat('D MMM YYYY') }}
@endif

@component('mail::button', ['url' => $responsesUrl])
{{ __('notifications.quote_submitted.action') }}
@endcomponent

{{ __('notifications.quote_submitted.personal_link') }}

{{ __('notifications.quote_submitted.disclaimer') }}

{{ __('notifications.quote_submitted.salutation') }}<br>
{{ config('app.name') }}
@endcomponent
