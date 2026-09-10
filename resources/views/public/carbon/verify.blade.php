<x-layouts.app
    :title="__('messages.carbon_verify.title', ['name' => $name])"
    :description="__('messages.carbon_verify.description')"
    noindex>
    <div class="mx-auto max-w-2xl px-4 py-10 sm:py-16">
        <p class="eyebrow">{{ __('messages.carbon_verify.eyebrow') }}</p>
        <h1 class="mt-3 text-2xl font-semibold text-ink dark:text-[#e4ddcf]">{{ $name }}</h1>
        <p class="mt-1 font-mono text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">{{ $publicId }}</p>

        <div class="mt-8 rounded-2xl border border-sand-200 bg-white p-6 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
            <dl class="grid grid-cols-1 gap-4 text-[0.9375rem] sm:grid-cols-2">
                <div>
                    <dt class="text-ink-soft dark:text-[#8f887b]">{{ __('messages.carbon_verify.registry_status') }}</dt>
                    <dd class="font-medium text-ink dark:text-[#e4ddcf]">{{ $registryStatusLabel }}</dd>
                </div>
                <div>
                    <dt class="text-ink-soft dark:text-[#8f887b]">{{ __('messages.carbon_verify.project_type') }}</dt>
                    <dd class="font-medium text-ink dark:text-[#e4ddcf]">{{ $projectType }}</dd>
                </div>
                <div>
                    <dt class="text-ink-soft dark:text-[#8f887b]">{{ __('messages.carbon_verify.developer') }}</dt>
                    <dd class="font-medium text-ink dark:text-[#e4ddcf]">
                        @if ($developerUrl)
                            <a href="{{ $developerUrl }}" class="hover:underline">{{ $developerName }}</a>
                        @else
                            {{ $developerName ?? '—' }}
                        @endif
                        @if ($developerVerified)
                            <span class="ml-1 rounded-full bg-green-100 px-2 py-0.5 text-[0.75rem] font-semibold text-green-800">{{ __('messages.carbon_verify.verified') }}</span>
                        @endif
                    </dd>
                </div>
                @if ($region)
                    <div>
                        <dt class="text-ink-soft dark:text-[#8f887b]">{{ __('messages.carbon_verify.region') }}</dt>
                        <dd class="font-medium text-ink dark:text-[#e4ddcf]">{{ $region }}</dd>
                    </div>
                @endif
                @if ($areaHectares)
                    <div>
                        <dt class="text-ink-soft dark:text-[#8f887b]">{{ __('messages.carbon_verify.area') }}</dt>
                        <dd class="font-medium text-ink dark:text-[#e4ddcf]">{{ number_format((float) $areaHectares, 2) }} ha</dd>
                    </div>
                @endif
            </dl>

            <div class="mt-6 border-t border-sand-200 pt-6 dark:border-[#2c2a24]">
                <dt class="text-ink-soft dark:text-[#8f887b]">{{ __('messages.carbon_verify.boundary') }}</dt>
                @if ($boundary && ($boundary['type'] ?? null) === 'Polygon')
                    @php($ring = $boundary['coordinates'][0] ?? [])
                    <dd class="mt-1 font-medium text-ink dark:text-[#e4ddcf]">
                        {{ __('messages.carbon_verify.boundary_points', ['count' => max(0, count($ring) - 1)]) }}
                    </dd>
                    <pre class="mt-2 overflow-x-auto rounded-xl bg-sand-50 p-3 text-[0.8125rem] text-ink-soft dark:bg-[#161511] dark:text-[#8f887b]">{{ json_encode($boundary) }}</pre>
                @else
                    <dd class="mt-1 text-ink-soft dark:text-[#8f887b]">{{ __('messages.carbon_verify.boundary_none') }}</dd>
                @endif
            </div>
        </div>

        <div class="mt-8 flex flex-col items-center gap-3">
            <div class="h-40 w-40">{!! $qrSvg !!}</div>
            <p class="break-all text-center font-mono text-[0.8125rem] text-ink-soft dark:text-[#8f887b]">{{ $verificationUrl }}</p>
        </div>

        <p class="mt-8 text-center text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">
            {{ __('messages.carbon_verify.report_concern') }}
            <a href="{{ route('contact') }}" class="underline">{{ __('messages.carbon_verify.report_concern_link') }}</a>
        </p>
    </div>
</x-layouts.app>
