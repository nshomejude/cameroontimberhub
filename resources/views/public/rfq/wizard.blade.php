@php
    use App\Services\RfqWizard;

    $stepLabel = __('messages.rfq_wizard.step_'.$step.'_label');
    $stepCaption = __('messages.rfq_wizard.step_'.$step.'_caption');
    $index = RfqWizard::indexOf($step);
    $total = count(RfqWizard::STEPS);
    $previous = RfqWizard::previous($step);
@endphp

<x-layouts.app
    :title="__('messages.rfq_wizard.title_step', ['n' => $index + 1, 'total' => $total])"
    :description="__('messages.rfq_wizard.meta_description')"
    noindex
    :breadcrumbs="[
        ['label' => __('messages.common.home'), 'url' => route('home')],
        ['label' => __('messages.rfq_wizard.breadcrumb_rfq_center'), 'url' => route('rfq.create')],
        ['label' => $stepLabel, 'url' => route('rfq.step', ['step' => $step])],
    ]">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 to-sand-50 dark:from-forest-950 dark:to-[#14130f]">
        <div class="mx-auto max-w-6xl px-4 py-8 sm:py-10">
            <p class="eyebrow">{{ __('messages.rfq_wizard.no_account_needed') }}</p>
            <h1 class="mt-2 font-display text-3xl font-semibold text-forest-950 dark:text-sand-100 sm:text-4xl">{{ __('messages.rfq_wizard.title') }}</h1>
            <p class="mt-2 max-w-2xl text-[1.125rem] text-ink-soft dark:text-[#b3ab9b]">
                {{ __('messages.rfq_wizard.intro') }}
            </p>
        </div>
    </section>

    <div class="mx-auto max-w-6xl px-4 py-6 sm:py-8">
        <x-rfq.stepper :current="$step" :wizard="$wizard" />

        @if (session('rfq_notice'))
            <p role="status" class="mt-4 rounded-xl border border-timber-200 bg-timber-50 px-4 py-3 text-[1.0625rem] text-timber-900">
                {{ session('rfq_notice') }}
            </p>
        @endif

        @if ($errors->any())
            <div role="alert" tabindex="-1" id="rfq-error-summary"
                 class="mt-4 rounded-xl border border-red-300 bg-red-50 px-4 py-3 dark:border-red-900 dark:bg-red-950/40">
                <p class="text-[1.0625rem] font-semibold text-red-800 dark:text-red-300">
                    {{ trans_choice('messages.rfq_wizard.problem_count', $errors->count(), ['count' => $errors->count()]) }}
                </p>
                <ul class="mt-1.5 list-disc space-y-0.5 pl-5 text-[1.0625rem] text-red-800 dark:text-red-300">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
            <main class="min-w-0 rounded-2xl border border-sand-200 dark:border-[#2c2a24] bg-white dark:bg-[#1f1d18] px-5 py-6 sm:px-7 sm:py-7">
                <p class="text-[0.9375rem] font-semibold uppercase tracking-wide text-forest-700 dark:text-forest-400">
                    {{ __('messages.rfq_wizard.step_n_of', ['n' => $index + 1, 'total' => $total]) }}
                </p>
                <h2 id="rfq-step-heading" tabindex="-1"
                    class="mt-1 font-display text-2xl font-semibold text-forest-950 focus-visible:outline-none dark:text-sand-100">
                    {{ $stepLabel }}
                </h2>
                <p class="mt-1 text-[1.0625rem] text-ink-soft dark:text-[#b3ab9b]">{{ $stepCaption }}</p>

                <form method="POST"
                      action="{{ $step === 'review' ? route('rfq.store') : route('rfq.step.store', ['step' => $step]) }}"
                      class="mt-6 space-y-7">
                    @csrf
                    <input type="hidden" name="form_rendered_at" value="{{ $formRenderedAt }}">
                    <input type="hidden" name="source" value="request_quote">
                    {{-- Honeypot: a real visitor never sees or fills this. --}}
                    <div class="hidden" aria-hidden="true">
                        <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                    </div>

                    @include('public.rfq.steps.'.$step)

                    <div class="flex flex-wrap items-center gap-3 border-t border-sand-200 pt-5 dark:border-[#2c2a24]">
                        @if ($previous)
                            <button type="submit" name="direction" value="back"
                                    class="inline-flex items-center gap-2 rounded-full border border-sand-300 px-5 py-2.5 text-[1.0625rem] font-semibold text-ink transition hover:bg-sand-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2 dark:border-[#3a352e] dark:text-[#e4ddcf] dark:hover:bg-[#26241e]">
                                <x-heroicon-m-arrow-left class="h-4 w-4" /> {{ __('messages.rfq_wizard.back') }}
                            </button>
                        @endif

                        <button type="submit" name="direction" value="next"
                                class="inline-flex items-center gap-2 rounded-full bg-forest-700 px-6 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2">
                            @if ($step === 'review')
                                <x-heroicon-m-paper-airplane class="h-4 w-4" /> {{ __('messages.rfq_wizard.submit_request') }}
                            @else
                                {{ __('messages.rfq_wizard.continue') }} <x-heroicon-m-arrow-right class="h-4 w-4" />
                            @endif
                        </button>
                    </div>
                </form>
            </main>

            <x-rfq.summary :wizard="$wizard" :species-by-id="$speciesById" :matching-suppliers="$matchingSuppliers"
                           class="lg:sticky lg:top-24" />
        </div>
    </div>

    {{-- Progressive enhancement only: every step above works without this.
         It moves focus to the error summary, or to the heading of the step the
         visitor just navigated to, matching the no-JS `autofocus` fallback. --}}
    <script>
        (function () {
            var target = document.getElementById('rfq-error-summary') || document.getElementById('rfq-step-heading');
            if (target && !document.querySelector('[autofocus]')) { target.focus({ preventScroll: false }); }
        })();
    </script>
</x-layouts.app>
