@php
    /**
     * Login — built from the approved mobile and desktop mockups.
     *
     * ONE form, TWO decorations. The mobile mockup is a full-bleed hero banner
     * above a centred form; the desktop mockup is a split panel with the same
     * form on the right. Duplicating the markup would duplicate every input id
     * (and break every `<label for>`), so the *form* is single and responsive
     * and only the surrounding artwork is swapped with lg:hidden / hidden lg:block.
     */
    $benefits = [
        ['icon' => 'shield-check', 'title' => 'Verified & Trusted Network', 'text' => 'Work with suppliers whose documents have been reviewed before listing.'],
        ['icon' => 'globe-alt', 'title' => 'Global Market Access', 'text' => 'Reach serious buyers and suppliers across the world.'],
        ['icon' => 'chart-bar', 'title' => 'Market Insights', 'text' => 'Track species, grades and export requirements in one place.'],
        ['icon' => 'lock-closed', 'title' => 'Secure & Transparent', 'text' => 'Your account and your enquiries stay private to you.'],
    ];

    $assurances = [
        ['icon' => 'shield-check', 'label' => 'Verified Suppliers'],
        ['icon' => 'sparkles', 'label' => 'Sustainable Trade'],
        ['icon' => 'lock-closed', 'label' => 'Secure Platform'],
        ['icon' => 'lifebuoy', 'label' => 'Dedicated Support'],
    ];
@endphp

<x-layouts.app
    title="Log In"
    description="Log in to your Cameroon Timber Hub buyer or supplier account."
    :noindex="true">

    <div class="bg-sand-50">
        <div class="mx-auto max-w-[1400px] px-0 lg:px-6 lg:py-8">
            <div class="overflow-hidden bg-white lg:grid lg:grid-cols-2 lg:rounded-3xl lg:border lg:border-sand-200 lg:shadow-sm">

                {{-- ---------- Mobile hero banner (mockup 1) ---------- --}}
                <div class="relative isolate overflow-hidden lg:hidden">
                    <img src="{{ asset('img/hero/timber-logs-mobile.jpg') }}" alt=""
                         width="828" height="620" fetchpriority="high" aria-hidden="true"
                         class="absolute inset-0 h-full w-full object-cover">
                    <div class="absolute inset-0 bg-gradient-to-b from-white/70 via-white/75 to-white" aria-hidden="true"></div>
                    <div class="relative flex flex-col items-center px-6 pb-10 pt-8">
                        <img src="/brand/logo-600.png" alt="Cameroon Timber Hub"
                             width="600" height="200" class="h-20 w-auto">
                    </div>
                </div>

                {{-- ---------- Desktop hero panel (mockup 3) ---------- --}}
                <aside class="relative isolate hidden overflow-hidden bg-forest-950 lg:block">
                    <img src="{{ asset('img/hero/timber-logs-forest.jpg') }}" alt=""
                         width="1600" height="1100" aria-hidden="true"
                         class="absolute inset-0 h-full w-full object-cover">
                    <div class="absolute inset-0 bg-gradient-to-r from-forest-950 via-forest-950/90 to-forest-950/30" aria-hidden="true"></div>

                    <div class="relative flex h-full flex-col justify-center px-12 py-16">
                        <h2 class="max-w-md text-[2.6rem] font-bold leading-[1.1] tracking-tight text-white">
                            Welcome back to
                            <span class="block text-timber-300">Cameroon Timber Hub</span>
                        </h2>
                        <span class="mt-6 block h-1 w-14 rounded-full bg-timber-400" aria-hidden="true"></span>

                        <p class="mt-6 max-w-sm text-[0.9375rem] leading-relaxed text-sand-200/90">
                            Access your account to connect with verified suppliers, explore quality
                            timber, and grow your business globally.
                        </p>

                        <ul class="mt-9 space-y-5">
                            @foreach ($benefits as $benefit)
                                <li class="flex gap-4">
                                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-timber-400/40 bg-white/5 text-timber-300">
                                        <x-dynamic-component :component="'heroicon-o-'.$benefit['icon']" class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <div>
                                        <p class="text-[0.9375rem] font-semibold text-white">{{ $benefit['title'] }}</p>
                                        <p class="mt-0.5 max-w-xs text-[0.8125rem] leading-relaxed text-sand-200/75">{{ $benefit['text'] }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>

                        @if ($stats['suppliers'] ?? null)
                            <p class="mt-10 flex w-fit items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-5 py-4 text-[0.875rem] text-sand-100">
                                <x-heroicon-s-check-badge class="h-6 w-6 shrink-0 text-timber-300" aria-hidden="true" />
                                <span>
                                    <strong class="font-semibold text-white" data-stat="suppliers">{{ number_format($stats['suppliers']) }}</strong>
                                    verified {{ Str::plural('supplier', $stats['suppliers']) }} listed
                                    @if ($stats['products'] ?? null)
                                        · <strong class="font-semibold text-white" data-stat="products">{{ number_format($stats['products']) }}</strong>
                                        active {{ Str::plural('listing', $stats['products']) }}
                                    @endif
                                </span>
                            </p>
                        @endif
                    </div>
                </aside>

                {{-- ---------- The form (shared by both breakpoints) ---------- --}}
                <div class="flex items-center justify-center px-5 pb-12 pt-2 lg:px-12 lg:py-16">
                    <div class="w-full max-w-md">
                        <h1 class="text-center text-[1.9rem] font-bold tracking-tight text-forest-800 lg:text-[1.75rem]">
                            <span class="lg:hidden">Welcome Back</span>
                            <span class="hidden lg:inline">Login to Your Account</span>
                        </h1>
                        <p class="mt-2 text-center text-[0.9375rem] leading-relaxed text-ink-soft">
                            <span class="lg:hidden">Sign in to your account and continue your timber trading journey.</span>
                            <span class="hidden lg:inline">Enter your credentials to access your account.</span>
                        </p>

                        @if (session('status'))
                            <p role="status" class="mt-6 rounded-xl border border-forest-200 bg-forest-50 px-4 py-3 text-[0.8125rem] text-forest-800">
                                {{ session('status') }}
                            </p>
                        @endif

                        <div class="mt-6">
                            <x-auth.error-summary />
                        </div>

                        <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-5" novalidate>
                            @csrf

                            <x-auth.field
                                name="email"
                                label="Email address"
                                type="email"
                                icon="envelope"
                                placeholder="Enter your email address"
                                autocomplete="email"
                                :required="true"
                                :autofocus="true" />

                            <x-auth.field
                                name="password"
                                label="Password"
                                type="password"
                                icon="lock-closed"
                                placeholder="Enter your password"
                                autocomplete="current-password"
                                :required="true"
                                :toggle="true" />

                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <label for="auth-remember" class="flex items-center gap-2 text-[0.8125rem] text-ink-soft">
                                    <input id="auth-remember" type="checkbox" name="remember" value="1" @checked(old('remember'))
                                           class="h-4 w-4 rounded border-sand-400 text-forest-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-forest-600">
                                    Remember me
                                </label>
                                <a href="{{ route('password.request') }}"
                                   class="text-[0.8125rem] font-semibold text-forest-700 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-forest-600">
                                    Forgot Password?
                                </a>
                            </div>

                            <button type="submit"
                                    class="flex w-full items-center justify-center gap-2.5 rounded-xl bg-forest-800 px-6 py-3.5 text-[0.9375rem] font-semibold text-white transition hover:bg-forest-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-forest-600">
                                <x-heroicon-o-lock-closed class="h-5 w-5" aria-hidden="true" />
                                Sign In
                            </button>
                        </form>

                        <p class="mt-7 text-center text-[0.875rem] text-ink-soft">
                            Don't have an account?
                            <a href="{{ route('register') }}"
                               class="font-semibold text-forest-700 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-forest-600">Create Account</a>
                        </p>
                    </div>
                </div>
            </div>

            {{-- ---------- Assurance strip (both mockups) ---------- --}}
            <ul class="mt-0 grid grid-cols-4 divide-x divide-sand-200 border-t border-sand-200 bg-sand-100 px-2 py-5 lg:mt-6 lg:rounded-2xl lg:border lg:px-6 lg:py-6">
                @foreach ($assurances as $item)
                    <li class="flex flex-col items-center gap-2 px-1 text-center lg:flex-row lg:gap-3 lg:px-6 lg:text-left">
                        <x-dynamic-component :component="'heroicon-o-'.$item['icon']" class="h-6 w-6 shrink-0 text-forest-700" aria-hidden="true" />
                        <span class="text-[0.6875rem] font-medium leading-tight text-ink lg:text-[0.875rem] lg:font-semibold">{{ $item['label'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</x-layouts.app>
