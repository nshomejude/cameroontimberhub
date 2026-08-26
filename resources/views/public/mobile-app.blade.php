@php
    $sent = session('app_notify_sent', false);
@endphp

<x-layouts.app
    title="Buyer App — Cameroon Timber Hub"
    description="Browse the timber catalogue, look up species and suppliers, and post a request for quote from your phone. The Cameroon Timber Hub buyer app is in development — leave your email to hear when it ships."
    :breadcrumbs="$breadcrumbs"
    :schema="$schema">

    <div class="bg-white">
        <div class="mx-auto max-w-[1400px] px-4 py-6 lg:px-6">

            {{-- Breadcrumb --}}
            <nav aria-label="Breadcrumb">
                <ol class="flex flex-wrap items-center gap-2 text-[0.9375rem] text-ink-soft">
                    <li><a href="{{ route('home') }}" class="rounded transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-300">Home</a></li>
                    <li aria-hidden="true">/</li>
                    <li><span aria-current="page" class="font-medium text-ink">Mobile App</span></li>
                </ol>
            </nav>

            {{-- Hero --}}
            <div class="mt-3 flex flex-col gap-6 lg:flex-row lg:items-center">
                <div class="min-w-0 flex-1">
                    <p class="eyebrow">Cameroon Timber Hub</p>
                    <h1 class="mt-1 text-[1.875rem] font-bold tracking-tight text-ink lg:text-[2.125rem]">
                        The buyer app is in development
                    </h1>
                    <p class="mt-2 max-w-2xl text-[1.125rem] leading-relaxed text-ink-soft">
                        Browse the catalogue, look up species and suppliers, post a request for quote and respond to the quotations you receive — all from your phone. Leave your email and we'll tell you the moment it ships.
                    </p>
                </div>
            </div>

            {{-- Download / notify --}}
            <section id="notify" class="mt-8 overflow-hidden rounded-xl border border-sand-300/70 bg-sand-50 px-6 py-8 lg:px-10 lg:py-10">
                @if ($apkUrl)
                    <div class="flex flex-col gap-6 lg:flex-row lg:items-center">
                        <div class="min-w-0 flex-1">
                            <h2 class="text-[1.5rem] font-bold text-ink">Download the app</h2>
                            <p class="mt-2 text-[1.0625rem] text-ink-soft">
                                @if ($apkVersion) Version {{ $apkVersion }}@endif
                                @if ($apkVersion && $apkSize) &middot; @endif
                                @if ($apkSize) {{ $apkSize }} @endif
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-3">
                            <a href="{{ $apkUrl }}" class="rounded-lg bg-forest-700 px-5 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800">Download APK</a>
                            @if ($playStoreUrl)
                                <a href="{{ $playStoreUrl }}" class="rounded-lg border border-sand-300 px-5 py-2.5 text-[1.0625rem] font-semibold text-ink transition hover:border-forest-600 hover:text-forest-700">Google Play</a>
                            @endif
                            @if ($appStoreUrl)
                                <a href="{{ $appStoreUrl }}" class="rounded-lg border border-sand-300 px-5 py-2.5 text-[1.0625rem] font-semibold text-ink transition hover:border-forest-600 hover:text-forest-700">App Store</a>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="mx-auto max-w-xl text-center">
                        <h2 class="text-[1.5rem] font-bold text-ink">Get notified when it launches</h2>
                        <p class="mt-2 text-[1.0625rem] text-ink-soft">No spam — one email, the day the app ships.</p>

                        <div role="status" aria-live="polite" class="empty:hidden">
                            @if ($sent)
                                <div tabindex="-1" autofocus
                                     class="mt-5 flex items-start gap-3 rounded-xl border border-forest-200 bg-forest-50 p-4 text-left">
                                    <x-heroicon-s-check-circle class="mt-0.5 h-5 w-5 shrink-0 text-forest-700" aria-hidden="true" />
                                    <p class="text-[1.0625rem] font-medium text-forest-800">
                                        Thanks — we'll email you the moment the app is available.
                                    </p>
                                </div>
                            @endif
                        </div>

                        @if ($errors->any())
                            <div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-left" role="alert">
                                <p class="text-[1.0625rem] font-semibold text-red-800">
                                    We couldn't save that address. Please correct the field below.
                                </p>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('mobile.app.notify') }}#notify" class="mt-5 space-y-3 text-left">
                            @csrf

                            {{-- Honeypot + minimum-time guard (AntiSpamService) --}}
                            <div class="hidden" aria-hidden="true">
                                <label for="mobile-app-website">Leave this field empty</label>
                                <input id="mobile-app-website" type="text" name="website" tabindex="-1" autocomplete="off" value="">
                            </div>
                            <input type="hidden" name="form_rendered_at" value="{{ now()->timestamp }}">

                            <div class="flex flex-col gap-3 sm:flex-row">
                                <label class="sr-only" for="mobile-app-email">Email address</label>
                                <input id="mobile-app-email" name="email" type="email" value="{{ old('email') }}"
                                       placeholder="you@company.com" required autocomplete="email"
                                       @error('email') aria-invalid="true" aria-describedby="mobile-app-email-error" @enderror
                                       class="w-full flex-1 rounded-lg border border-sand-300 bg-white px-4 py-2.5 text-[1.0625rem] text-ink placeholder:text-ink-soft/60 transition focus:border-forest-600 focus:outline-none focus:ring-2 focus:ring-forest-600/25">
                                <button type="submit"
                                        class="shrink-0 rounded-lg bg-forest-700 px-5 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                                    Notify me
                                </button>
                            </div>
                            @error('email')
                                <p id="mobile-app-email-error" class="text-[0.9375rem] font-medium text-red-700">{{ $message }}</p>
                            @enderror

                            <fieldset class="flex flex-wrap justify-center gap-4">
                                <legend class="sr-only">Platform</legend>
                                @foreach (['any' => 'Either platform', 'android' => 'Android', 'ios' => 'iOS'] as $value => $label)
                                    <label class="flex items-center gap-2 text-[0.9375rem] text-ink-soft">
                                        <input type="radio" name="platform" value="{{ $value }}" @checked(old('platform', 'any') === $value)
                                               class="h-4 w-4 border-sand-300 text-forest-700 focus:ring-forest-500">
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </fieldset>
                        </form>
                    </div>
                @endif
            </section>

            {{-- Screens --}}
            <section class="mt-12">
                <h2 class="text-[1.5rem] font-bold tracking-tight text-ink">What it looks like</h2>
                <div class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($screens as $screen)
                        <figure class="overflow-hidden rounded-xl border border-sand-300/70 bg-white">
                            <img src="{{ asset($screen['src']) }}" alt="{{ $screen['alt'] }}" loading="lazy"
                                 class="aspect-[9/16] w-full object-cover">
                            <figcaption class="p-4">
                                <p class="text-[1.0625rem] font-bold text-ink">{{ $screen['title'] }}</p>
                                <p class="mt-1 text-[0.9375rem] leading-relaxed text-ink-soft">{{ $screen['caption'] }}</p>
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            </section>

            {{-- Features --}}
            <section class="mt-12">
                <h2 class="text-[1.5rem] font-bold tracking-tight text-ink">What it does</h2>
                <div class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($features as $feature)
                        <div class="rounded-xl border border-sand-300/70 bg-white p-5">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-forest-50 text-forest-700">
                                <x-dynamic-component :component="'heroicon-o-'.$feature['icon']" class="h-6 w-6" />
                            </span>
                            <h3 class="mt-3 text-[1.0625rem] font-bold text-ink">{{ $feature['title'] }}</h3>
                            <p class="mt-1.5 text-[0.9375rem] leading-relaxed text-ink-soft">{{ $feature['text'] }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Funnel CTA --}}
            <section class="mt-12 overflow-hidden rounded-xl bg-forest-800 px-6 py-8 text-white lg:px-10 lg:py-10">
                <div class="flex flex-col gap-6 lg:flex-row lg:items-center">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-[1.5rem] font-bold">Buying now? You don't have to wait for the app.</h2>
                        <p class="mt-2 max-w-2xl text-[1.125rem] leading-relaxed text-forest-100">
                            Everything above already works on the website — browse the catalogue and post a request for quote today.
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-3">
                        <a href="{{ route('rfq.create') }}" class="rounded-lg bg-white px-5 py-2.5 text-[1.0625rem] font-semibold text-forest-800 transition hover:bg-forest-50">Request a quote</a>
                        <a href="{{ route('directory') }}" class="rounded-lg border border-white/40 px-5 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-white/10">Browse suppliers</a>
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-layouts.app>
