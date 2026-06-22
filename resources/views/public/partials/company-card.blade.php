@php($name = $company->name)
<a href="{{ route('companies.show', $company->slug) }}"
   class="block rounded-lg border border-stone-200 bg-white p-5 transition hover:border-amber-500 hover:shadow-sm">
    <div class="flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded bg-amber-100 text-sm font-bold text-amber-800">
            {{ strtoupper(\Illuminate\Support\Str::substr($name, 0, 2)) }}
        </span>
        <div class="min-w-0">
            <h3 class="truncate font-semibold text-stone-900">{{ $name }}</h3>
            <p class="text-sm text-stone-500">{{ $company->region }}@if($company->city), {{ $company->city }}@endif</p>
        </div>
        @if($company->is_featured)
            <span class="ml-auto rounded bg-amber-600 px-2 py-0.5 text-xs font-semibold text-white">Featured</span>
        @endif
    </div>

    @if($company->relationLoaded('species') && $company->species->isNotEmpty())
        <div class="mt-4 flex flex-wrap gap-1.5">
            @foreach($company->species->take(4) as $sp)
                <span class="rounded bg-stone-100 px-2 py-0.5 text-xs text-stone-600">{{ $sp->common_name }}</span>
            @endforeach
        </div>
    @endif

    <p class="mt-4 text-xs font-medium text-green-700">
        &check; Verified profile
        @if($company->verified_at)
            <span class="text-stone-400">· {{ $company->verified_at->format('M Y') }}</span>
        @endif
    </p>
</a>
