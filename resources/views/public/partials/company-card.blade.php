@php($name = $company->name)
<a href="{{ route('companies.show', $company->slug) }}"
   class="group flex flex-col rounded-2xl border border-sand-200 dark:border-[#2c2a24] bg-white dark:bg-[#1f1d18] p-6 shadow-[0_1px_0_rgba(0,0,0,0.02)] transition hover:-translate-y-0.5 hover:border-forest-200 hover:shadow-lg">
    <div class="flex items-start gap-4">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-forest-700 font-display text-lg font-semibold text-sand-100">
            {{ strtoupper(\Illuminate\Support\Str::substr($name, 0, 1)) }}
        </span>
        <div class="min-w-0 flex-1">
            <h3 class="truncate font-display text-lg font-semibold text-forest-900 dark:text-sand-100 group-hover:text-forest-700">{{ $name }}</h3>
            <p class="mt-0.5 flex items-center gap-1 text-sm text-ink-soft dark:text-[#b3ab9b]">
                <x-heroicon-m-map-pin class="h-4 w-4 text-timber-500" />
                {{ $company->region }}@if($company->city), {{ $company->city }}@endif
            </p>
        </div>
        @if($company->is_featured)
            <span class="rounded-full bg-timber-100 dark:bg-timber-400/15 px-2.5 py-0.5 text-xs font-semibold text-timber-800 dark:text-timber-200">Featured</span>
        @endif
    </div>

    @if($company->relationLoaded('species') && $company->species->isNotEmpty())
        <div class="mt-4 flex flex-wrap gap-1.5">
            @foreach($company->species->take(4) as $sp)
                <span class="rounded-full bg-sand-100 dark:bg-[#26241e] px-2.5 py-1 text-xs font-medium text-ink-soft dark:text-[#b3ab9b]">{{ $sp->common_name }}</span>
            @endforeach
        </div>
    @endif

    <div class="mt-5 flex items-center justify-between border-t border-sand-100 dark:border-[#26241e] pt-4">
        <span class="inline-flex items-center gap-1.5 text-sm font-medium text-forest-600 dark:text-forest-400">
            <x-heroicon-s-check-badge class="h-5 w-5" />
            Verified profile
        </span>
        @if($company->verified_at)
            <span class="text-xs text-ink-soft dark:text-[#b3ab9b]">{{ $company->verified_at->format('M Y') }}</span>
        @endif
    </div>
</a>
