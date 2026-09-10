@component('mail::message')
# {{ __('notifications.contact_message.heading') }}

**{{ __('notifications.contact_message.name') }}** {{ $data['name'] }}
@if (filled($data['company'] ?? null))
**{{ __('notifications.contact_message.company') }}** {{ $data['company'] }}
@endif
**{{ __('notifications.contact_message.email') }}** {{ $data['email'] }}
@if (filled($data['phone'] ?? null))
**{{ __('notifications.contact_message.phone') }}** {{ $data['phone'] }}
@endif
**{{ __('notifications.contact_message.subject_label') }}** {{ $data['subject'] }}

---

{{ $data['message'] }}

---

{{ __('notifications.contact_message.reply_hint') }}

{{ config('app.name') }}
@endcomponent
