@php
    /**
     * Contact — approved mockup layout.
     *
     * Copy comes from the CMS `pages` row (`$page->data`); the address, phone
     * numbers, mailboxes, office hours and social profiles come from
     * config/contact.php, overridable per-page from the CMS, so none of it is
     * hardcoded here. See ContactController::details().
     */
    $d = is_array($page->data) ? $page->data : [];

    $pillars = $d['pillars'] ?? [
        ['icon' => 'lifebuoy', 'title' => 'Dedicated Support', 'text' => 'Our team is always ready to help.'],
        ['icon' => 'clock', 'title' => 'Fast Response', 'text' => 'We respond within 24 business hours.'],
        ['icon' => 'shield-check', 'title' => 'Trusted Partner', 'text' => 'Your success is our priority.'],
        ['icon' => 'globe-alt', 'title' => 'Global Network', 'text' => 'Connecting verified buyers and suppliers worldwide.'],
    ];

    // Focus management: on a failed submit the browser lands the caret on the
    // first field that actually has an error (no JavaScript required).
    $fieldOrder = ['name', 'company', 'email', 'phone', 'subject', 'message', 'consent'];
    $firstError = collect($fieldOrder)->first(fn ($f) => $errors->has($f));
    $sent = session('contact_sent');
@endphp

<x-layouts.app
    :title="$page->title"
    :description="$page->meta_description"
    :schema="$schema ?? null"
    :breadcrumbs="$breadcrumbs ?? null"
    :image="asset('img/hero/douala-waterfront.jpg')">

    {{-- ==================================================================
         1. HERO
    =================================================================== --}}
    <section class="relative isolate overflow-hidden bg-forest-950" aria-labelledby="contact-heading">
        <img src="{{ asset('img/hero/douala-waterfront.jpg') }}"
             alt="The Douala waterfront at sunset, seen from across the Wouri river"
             width="1400" height="868" fetchpriority="high"
             class="absolute inset-0 h-full w-full object-cover object-right">
        <div class="absolute inset-0 bg-gradient-to-r from-forest-950 via-forest-950/92 to-forest-950/25 lg:to-transparent" aria-hidden="true"></div>

        <div class="relative mx-auto max-w-[80rem] px-5 pb-10 pt-9 lg:flex lg:items-end lg:gap-10 lg:px-8 lg:pb-12 lg:pt-14">
            <div class="min-w-0 lg:max-w-[34rem] lg:flex-1">
                <p class="text-[0.75rem] font-bold uppercase tracking-[0.12em] text-timber-300 lg:text-[0.8125rem]">
                    {{ $d['eyebrow'] ?? 'Contact us' }}
                </p>

                <h1 id="contact-heading" class="mt-4 text-[2.1rem] font-bold leading-[1.14] tracking-tight text-white lg:text-[3rem] lg:leading-[1.1]">
                    {{ $page->h1 ?: $page->title }}
                </h1>

                <span class="mt-5 block h-[3px] w-14 rounded-full bg-timber-400" aria-hidden="true"></span>

                @if ($intro = ($d['intro'] ?? $page->meta_description))
                    <p class="mt-5 max-w-[28rem] text-[0.95rem] leading-relaxed text-sand-200/90 lg:text-[1.0625rem] lg:leading-[1.7]">
                        {{ $intro }}
                    </p>
                @endif
            </div>

            {{-- Head-quarters card (mirrors the mockup overlay) --}}
            <div class="mt-7 rounded-xl border border-white/25 bg-forest-950/80 p-5 backdrop-blur-sm lg:mt-0 lg:w-[19rem] lg:shrink-0">
                <div class="flex items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-timber-500 text-white" aria-hidden="true">
                        <x-heroicon-s-map-pin class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[0.8125rem] text-sand-200/85">Our head quarters</p>
                        <p class="mt-0.5 text-[1.25rem] font-bold leading-tight text-white">
                            {{ $details['address']['locality'] }}, Cameroon
                        </p>
                        <p class="mt-2 text-[0.8125rem] leading-relaxed text-sand-200/85">
                            {{ $d['hq_note'] ?? 'The economic capital of Cameroon, and our home.' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ==================================================================
         2. PILLARS
    =================================================================== --}}
    <section class="bg-sand-100" aria-label="How we support you">
        <ul class="mx-auto grid max-w-[80rem] grid-cols-2 gap-x-4 gap-y-8 px-5 py-9 sm:grid-cols-4 lg:gap-x-0 lg:divide-x lg:divide-sand-300 lg:px-8">
            @foreach ($pillars as $pillar)
                <li class="flex flex-col items-center gap-3 text-center lg:px-6 lg:first:pl-0 lg:last:pr-0">
                    <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-forest-900 text-forest-200" aria-hidden="true">
                        <x-dynamic-component :component="'heroicon-o-'.($pillar['icon'] ?? 'check-badge')" class="h-6 w-6" />
                    </span>
                    <div>
                        <h2 class="text-[0.9375rem] font-bold leading-snug text-ink">{{ $pillar['title'] ?? '' }}</h2>
                        <p class="mt-1.5 text-[0.8125rem] leading-relaxed text-ink-soft">{{ $pillar['text'] ?? '' }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- ==================================================================
         3. DETAILS  |  FORM  |  FIND US
    =================================================================== --}}
    <div class="bg-sand-50">
        <div class="mx-auto grid max-w-[80rem] gap-10 px-5 py-10 lg:grid-cols-[0.9fr_1.25fr_1fr] lg:gap-12 lg:px-8 lg:py-14">

            {{-- ---------- Get in touch ---------- --}}
            <section aria-labelledby="details-heading">
                <h2 id="details-heading" class="text-[1.4rem] font-bold tracking-tight text-ink">Get in Touch</h2>
                <span class="mt-3 block h-[3px] w-10 rounded-full bg-timber-500" aria-hidden="true"></span>

                @if ($blurb = ($d['details_blurb'] ?? null))
                    <p class="mt-5 text-[0.875rem] leading-relaxed text-ink-soft">{{ $blurb }}</p>
                @endif

                <dl class="mt-6 divide-y divide-sand-200 border-t border-sand-200">
                    <div class="flex items-start gap-4 py-5">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-forest-900 text-forest-200" aria-hidden="true">
                            <x-heroicon-o-building-office-2 class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <dt class="text-[0.875rem] font-bold text-ink">{{ $details['address']['label'] }}</dt>
                            <dd class="mt-1 text-[0.875rem] leading-relaxed text-ink-soft">
                                @foreach ($details['address']['lines'] as $line)
                                    <span class="block">{{ $line }}</span>
                                @endforeach
                            </dd>
                        </div>
                    </div>

                    @if (! empty($details['phones']))
                        <div class="flex items-start gap-4 py-5">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-forest-900 text-forest-200" aria-hidden="true">
                                <x-heroicon-o-phone class="h-5 w-5" />
                            </span>
                            <div class="min-w-0">
                                <dt class="text-[0.875rem] font-bold text-ink">Phone</dt>
                                <dd class="mt-1 text-[0.875rem] leading-relaxed text-ink-soft">
                                    @foreach ($details['phones'] as $phone)
                                        <a class="block transition hover:text-forest-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-forest-700"
                                           href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}">{{ $phone }}</a>
                                    @endforeach
                                </dd>
                            </div>
                        </div>
                    @endif

                    @if (! empty($details['emails']))
                        <div class="flex items-start gap-4 py-5">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-forest-900 text-forest-200" aria-hidden="true">
                                <x-heroicon-o-envelope class="h-5 w-5" />
                            </span>
                            <div class="min-w-0">
                                <dt class="text-[0.875rem] font-bold text-ink">Email</dt>
                                <dd class="mt-1 break-words text-[0.875rem] leading-relaxed text-ink-soft">
                                    @foreach ($details['emails'] as $email)
                                        <a class="block transition hover:text-forest-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-forest-700"
                                           href="mailto:{{ $email }}">{{ $email }}</a>
                                    @endforeach
                                </dd>
                            </div>
                        </div>
                    @endif

                    @if (! empty($details['website']))
                        <div class="flex items-start gap-4 py-5">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-forest-900 text-forest-200" aria-hidden="true">
                                <x-heroicon-o-globe-alt class="h-5 w-5" />
                            </span>
                            <div class="min-w-0">
                                <dt class="text-[0.875rem] font-bold text-ink">Website</dt>
                                <dd class="mt-1 break-words text-[0.875rem] leading-relaxed text-ink-soft">{{ $details['website'] }}</dd>
                            </div>
                        </div>
                    @endif
                </dl>
            </section>

            {{-- ---------- Send us a message ---------- --}}
            <section aria-labelledby="form-heading">
                <h2 id="form-heading" class="text-[1.4rem] font-bold tracking-tight text-ink">Send Us a Message</h2>
                <span class="mt-3 block h-[3px] w-10 rounded-full bg-timber-500" aria-hidden="true"></span>
                <p class="mt-5 text-[0.875rem] text-ink-soft">Fill out the form below and we'll get back to you.</p>

                {{-- Success (announced to assistive tech, and focused on load) --}}
                <div role="status" aria-live="polite" class="empty:hidden">
                    @if ($sent)
                        <div tabindex="-1" autofocus
                             class="mt-6 flex items-start gap-3 rounded-xl border border-forest-200 bg-forest-50 p-4">
                            <x-heroicon-s-check-circle class="mt-0.5 h-5 w-5 shrink-0 text-forest-700" aria-hidden="true" />
                            <p class="text-[0.875rem] font-medium text-forest-800">
                                Message sent — thank you. Our team will be in touch within 24 business hours.
                            </p>
                        </div>
                    @endif
                </div>

                @if ($errors->any())
                    <div class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4" role="alert">
                        <p class="text-[0.875rem] font-semibold text-red-800">
                            Your message wasn't sent. Please correct {{ $errors->count() === 1 ? 'the field' : 'the fields' }} highlighted below.
                        </p>
                    </div>
                @endif

                <form method="POST" action="{{ route('contact.store') }}" class="mt-6 space-y-4" novalidate>
                    @csrf

                    {{-- Honeypot + minimum-time guard (AntiSpamService) --}}
                    <div class="hidden" aria-hidden="true">
                        <label for="website">Leave this field empty</label>
                        <input id="website" type="text" name="website" tabindex="-1" autocomplete="off" value="">
                    </div>
                    <input type="hidden" name="form_rendered_at" value="{{ now()->timestamp }}">

                    @php
                        $input = 'block w-full rounded-lg border border-sand-300 bg-white px-4 py-3 text-[0.9375rem] text-ink placeholder:text-ink-soft/60 transition focus:border-forest-600 focus:outline-none focus:ring-2 focus:ring-forest-600/25';
                        $inputError = 'border-red-400 focus:border-red-500 focus:ring-red-500/25';
                    @endphp

                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ([
                            ['name', 'Full Name', 'text', 'Full Name', true, 'name'],
                            ['company', 'Company Name', 'text', 'Company Name', false, 'organization'],
                            ['email', 'Email Address', 'email', 'Email Address', true, 'email'],
                            ['phone', 'Phone Number', 'tel', 'Phone Number', false, 'tel'],
                        ] as [$field, $label, $type, $placeholder, $required, $autocomplete])
                            <div>
                                <label for="contact-{{ $field }}" class="block text-[0.8125rem] font-medium text-ink">
                                    {{ $label }}@if ($required)<span class="text-red-600" aria-hidden="true"> *</span>@endif
                                </label>
                                <input id="contact-{{ $field }}" name="{{ $field }}" type="{{ $type }}"
                                       value="{{ old($field) }}" placeholder="{{ $placeholder }}"
                                       autocomplete="{{ $autocomplete }}"
                                       @if ($required) required @endif
                                       @if ($firstError === $field) autofocus @endif
                                       @error($field) aria-invalid="true" aria-describedby="contact-{{ $field }}-error" @enderror
                                       class="mt-1.5 @error($field) {{ $input.' '.$inputError }} @else {{ $input }} @enderror">
                                @error($field)
                                    <p id="contact-{{ $field }}-error" class="mt-1.5 text-[0.75rem] font-medium text-red-700">{{ $message }}</p>
                                @enderror
                            </div>
                        @endforeach
                    </div>

                    <div>
                        <label for="contact-subject" class="block text-[0.8125rem] font-medium text-ink">
                            Subject<span class="text-red-600" aria-hidden="true"> *</span>
                        </label>
                        <input id="contact-subject" name="subject" type="text" value="{{ old('subject') }}"
                               placeholder="Subject" required
                               @if ($firstError === 'subject') autofocus @endif
                               @error('subject') aria-invalid="true" aria-describedby="contact-subject-error" @enderror
                               class="mt-1.5 @error('subject') {{ $input.' '.$inputError }} @else {{ $input }} @enderror">
                        @error('subject')
                            <p id="contact-subject-error" class="mt-1.5 text-[0.75rem] font-medium text-red-700">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="contact-message" class="block text-[0.8125rem] font-medium text-ink">
                            Your Message<span class="text-red-600" aria-hidden="true"> *</span>
                        </label>
                        <textarea id="contact-message" name="message" rows="6" placeholder="Your Message" required
                                  @if ($firstError === 'message') autofocus @endif
                                  aria-describedby="@error('message') contact-message-error @else contact-message-hint @enderror"
                                  @error('message') aria-invalid="true" @enderror
                                  class="mt-1.5 @error('message') {{ $input.' '.$inputError }} @else {{ $input }} @enderror">{{ old('message') }}</textarea>
                        @error('message')
                            <p id="contact-message-error" class="mt-1.5 text-[0.75rem] font-medium text-red-700">{{ $message }}</p>
                        @else
                            <p id="contact-message-hint" class="mt-1.5 text-[0.75rem] text-ink-soft">At least 20 characters, so we can route your request to the right team.</p>
                        @enderror
                    </div>

                    <div>
                        <div class="flex items-start gap-3">
                            <input id="contact-consent" name="consent" type="checkbox" value="1"
                                   @checked(old('consent'))
                                   @if ($firstError === 'consent') autofocus @endif
                                   @error('consent') aria-invalid="true" aria-describedby="contact-consent-error" @enderror
                                   class="mt-0.5 h-4 w-4 shrink-0 rounded border-sand-300 text-forest-700 focus:ring-forest-600">
                            <label for="contact-consent" class="text-[0.8125rem] leading-relaxed text-ink-soft">
                                I agree that {{ config('app.name') }} may use this information to respond to my message.
                            </label>
                        </div>
                        @error('consent')
                            <p id="contact-consent-error" class="mt-1.5 text-[0.75rem] font-medium text-red-700">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit"
                            class="inline-flex w-full items-center justify-center gap-2.5 rounded-lg bg-forest-800 px-7 py-3.5 text-[0.9375rem] font-semibold text-white transition hover:bg-forest-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-forest-700">
                        <x-heroicon-o-paper-airplane class="h-5 w-5" aria-hidden="true" /> Send Message
                    </button>
                </form>
            </section>

            {{-- ---------- Find us + office hours ---------- --}}
            <section aria-labelledby="find-us-heading">
                <h2 id="find-us-heading" class="text-[1.4rem] font-bold tracking-tight text-ink">Find Us</h2>
                <span class="mt-3 block h-[3px] w-10 rounded-full bg-timber-500" aria-hidden="true"></span>

                {{-- Deliberately not an embedded third-party map: an iframe would
                     need a CSP exception and would hand every visitor's IP to the
                     map vendor before they consent. A plain outbound link does not. --}}
                <div class="mt-6 overflow-hidden rounded-xl border border-sand-200 bg-white">
                    <div class="flex items-start gap-4 bg-forest-900 p-5">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-timber-500 text-white" aria-hidden="true">
                            <x-heroicon-s-map-pin class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[0.9375rem] font-bold text-white">{{ $details['organisation'] }} HQ</p>
                            <p class="mt-1 text-[0.8125rem] leading-relaxed text-sand-200/85">
                                {{ $details['address']['lines'][0] ?? $details['address']['locality'] }}
                            </p>
                        </div>
                    </div>
                    <a href="{{ $mapUrl }}" target="_blank" rel="noopener noreferrer"
                       class="flex items-center justify-between gap-3 px-5 py-4 text-[0.875rem] font-semibold text-forest-700 transition hover:bg-sand-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-forest-700">
                        <span>Open in maps<span class="sr-only"> (opens in a new tab)</span></span>
                        <x-heroicon-m-arrow-top-right-on-square class="h-4 w-4" aria-hidden="true" />
                    </a>
                </div>

                @if (! empty($details['hours']))
                    <h3 class="mt-9 text-[1.15rem] font-bold tracking-tight text-ink">Office Hours</h3>
                    <span class="mt-3 block h-[3px] w-10 rounded-full bg-timber-500" aria-hidden="true"></span>

                    <div class="mt-5 flex items-start gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-forest-900 text-forest-200" aria-hidden="true">
                            <x-heroicon-o-clock class="h-5 w-5" />
                        </span>
                        <dl class="min-w-0 flex-1 space-y-2">
                            @foreach ($details['hours'] as $slot)
                                <div class="flex flex-wrap justify-between gap-x-4 text-[0.8125rem]">
                                    <dt class="text-ink">{{ $slot['days'] }}</dt>
                                    <dd class="text-ink-soft">{{ $slot['time'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>

                    @if (! empty($details['hours_note']))
                        <p class="mt-4 text-[0.8125rem] text-ink-soft">{{ $details['hours_note'] }}</p>
                    @endif
                @endif

                @if (! empty(array_filter($details['social'] ?? [])))
                    <h3 class="mt-9 text-[0.8125rem] font-bold uppercase tracking-wider text-ink">Follow us</h3>
                    <ul class="mt-3 flex flex-wrap gap-2">
                        @foreach (array_filter($details['social']) as $network => $url)
                            <li>
                                <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
                                   class="inline-flex rounded-lg border border-sand-300 px-3 py-2 text-[0.8125rem] font-medium capitalize text-ink transition hover:border-forest-600 hover:text-forest-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-forest-700">
                                    {{ $network }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>

    {{-- ==================================================================
         4. CTA BAND
    =================================================================== --}}
    <section class="relative isolate overflow-hidden bg-forest-950" aria-labelledby="contact-cta-heading">
        <img src="{{ asset('img/misc/cta-log-cross-section.jpg') }}" alt="" aria-hidden="true" loading="lazy"
             class="absolute inset-y-0 right-0 h-full w-full object-cover lg:w-[42%]">
        <div class="absolute inset-0 bg-gradient-to-r from-forest-950 via-forest-950/95 to-forest-950/60" aria-hidden="true"></div>

        <div class="relative mx-auto flex max-w-[80rem] flex-col gap-6 px-5 py-9 lg:flex-row lg:items-center lg:px-8 lg:py-10">
            <div class="min-w-0 flex-1">
                <h2 id="contact-cta-heading" class="text-[1.35rem] font-bold leading-tight tracking-tight text-white lg:text-[1.75rem]">
                    {{ $d['cta_title'] ?? 'Let’s build a sustainable future together' }}
                </h2>
                <p class="mt-2.5 max-w-[34rem] text-[0.875rem] leading-relaxed text-sand-200/90">
                    {{ $d['cta_text'] ?? 'Whether you are a supplier, buyer, investor or partner, we would like to hear from you.' }}
                </p>
            </div>

            <div class="flex shrink-0 flex-col gap-3 sm:flex-row lg:gap-4">
                <a href="{{ route('register') }}"
                   class="inline-flex items-center justify-center gap-2.5 rounded-lg bg-forest-700 px-7 py-3.5 text-[0.9375rem] font-semibold text-white transition hover:bg-forest-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                    <x-heroicon-o-user-plus class="h-5 w-5" aria-hidden="true" /> Join as Supplier
                </a>
                <a href="{{ route('marketplace') }}"
                   class="inline-flex items-center justify-center gap-2.5 rounded-lg border border-white/60 px-7 py-3.5 text-[0.9375rem] font-semibold text-white transition hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                    <x-heroicon-o-shopping-cart class="h-5 w-5" aria-hidden="true" /> Explore Marketplace
                </a>
            </div>
        </div>
    </section>
</x-layouts.app>
