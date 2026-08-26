@component('mail::message')
# New contact form message

**Name:** {{ $data['name'] }}
@if (filled($data['company'] ?? null))
**Company:** {{ $data['company'] }}
@endif
**Email:** {{ $data['email'] }}
@if (filled($data['phone'] ?? null))
**Phone:** {{ $data['phone'] }}
@endif
**Subject:** {{ $data['subject'] }}

---

{{ $data['message'] }}

---

Reply directly to this email to answer the sender.

{{ config('app.name') }}
@endcomponent
