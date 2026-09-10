<x-layouts.app
    :title="$project->name.' — Carbon Projects'"
    :description="\Illuminate\Support\Str::limit(strip_tags((string) $project->description), 160) ?: __('messages.carbon.meta_fallback')"
    :breadcrumbs="$breadcrumbs">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-5xl px-4 py-12">
            <p class="eyebrow">{{ __('messages.carbon.heading') }}</p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl">{{ $project->name }}</h1>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                @if ($project->project_type)
                    <span class="inline-flex items-center rounded-full bg-forest-100 px-3 py-1 text-sm font-semibold text-forest-800">
                        {{ ucwords(str_replace('_', ' ', $project->project_type)) }}
                    </span>
                @endif
                @if ($project->region)
                    <span class="flex items-center gap-1 text-sm text-ink-soft dark:text-[#b3ab9b]">
                        <x-heroicon-s-map-pin class="h-4 w-4 shrink-0 text-forest-600" />
                        {{ $project->region }}
                    </span>
                @endif
            </div>
        </div>
    </section>

    <div class="mx-auto max-w-5xl px-4 py-10">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">

            <div class="lg:col-span-2 space-y-6">
                <div class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                    <h2 class="text-[1.0625rem] font-bold text-ink dark:text-sand-100">{{ __('messages.carbon.project_details') }}</h2>
                    <dl class="mt-4 divide-y divide-sand-200 dark:divide-[#2c2a24]">
                        @if ($project->project_type)
                            <div class="flex justify-between gap-4 py-2.5 text-[0.9375rem]">
                                <dt class="text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.carbon.project_type') }}</dt>
                                <dd class="font-semibold text-ink dark:text-sand-100">{{ ucwords(str_replace('_', ' ', $project->project_type)) }}</dd>
                            </div>
                        @endif
                        @if ($project->region)
                            <div class="flex justify-between gap-4 py-2.5 text-[0.9375rem]">
                                <dt class="text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.carbon.region') }}</dt>
                                <dd class="font-semibold text-ink dark:text-sand-100">{{ $project->region }}</dd>
                            </div>
                        @endif
                        @if ($project->area_hectares !== null)
                            <div class="flex justify-between gap-4 py-2.5 text-[0.9375rem]">
                                <dt class="text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.carbon.area') }}</dt>
                                <dd class="font-semibold text-ink dark:text-sand-100">{{ number_format((float) $project->area_hectares) }} ha</dd>
                            </div>
                        @endif
                        @if ($project->estimated_credits_per_year !== null)
                            <div class="flex justify-between gap-4 py-2.5 text-[0.9375rem]">
                                <dt class="text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.carbon.est_credits_year') }}</dt>
                                <dd class="font-semibold text-ink dark:text-sand-100">{{ number_format((float) $project->estimated_credits_per_year) }}</dd>
                            </div>
                        @endif
                        @if ($project->public_id)
                            <div class="flex justify-between gap-4 py-2.5 text-[0.9375rem]">
                                <dt class="text-ink-soft dark:text-[#b3ab9b]">{{ __('messages.carbon_verify.registry_status') }}</dt>
                                <dd class="font-semibold text-ink dark:text-sand-100">{{ $project->registry_status?->label() }}</dd>
                            </div>
                            @if ($project->registry_status?->isPubliclyVerifiable())
                                <div class="flex justify-between gap-4 py-2.5 text-[0.9375rem]">
                                    <dt class="text-ink-soft dark:text-[#b3ab9b]">{{ $project->public_id }}</dt>
                                    <dd><a href="{{ route('carbon.verify', $project->public_id) }}" class="font-semibold text-forest-700 underline dark:text-forest-300">{{ __('messages.carbon_verify.verify_cta') }}</a></dd>
                                </div>
                            @endif
                        @endif
                    </dl>
                </div>

                @if ($project->description)
                    <div class="rounded-2xl border border-sand-200 bg-white p-5 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                        <h2 class="text-[1.0625rem] font-bold text-ink dark:text-sand-100">{{ __('messages.carbon.about_this_project') }}</h2>
                        <div class="mt-3 space-y-3 text-[1.0625rem] leading-relaxed text-ink-soft dark:text-[#b3ab9b]">
                            @foreach (preg_split('/\n{2,}/', (string) $project->description) as $para)
                                @if (trim($para) !== '')
                                    <p>{{ trim($para) }}</p>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="lg:col-span-1">
                @if ($company)
                    <h2 class="mb-3 text-[1.0625rem] font-bold text-ink dark:text-sand-100">{{ __('messages.carbon.listed_by') }}</h2>
                    <x-supplier-card :company="$company" compact />
                @endif
            </div>
        </div>
    </div>

</x-layouts.app>
