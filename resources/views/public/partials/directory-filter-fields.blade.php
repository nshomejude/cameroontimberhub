@php($field = 'w-full rounded-lg border border-sand-300 dark:border-[#3a352e] bg-sand-50/60 dark:bg-[#26241e] px-3 py-2.5 text-sm text-ink dark:text-[#f1ece1] focus:border-forest-500 focus:bg-white focus:ring-2 focus:ring-forest-100 focus:outline-none')
<form method="GET" action="{{ route('directory') }}" class="space-y-3">
    <label class="relative block">
        <x-heroicon-m-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-soft/60" />
        <input type="search" name="q" value="{{ $filters['term'] }}" placeholder="Search exporters…"
               class="w-full rounded-lg border border-sand-300 dark:border-[#3a352e] bg-sand-50/60 dark:bg-[#26241e] py-2.5 pl-10 pr-3 text-sm text-ink dark:text-[#f1ece1] placeholder:text-ink-soft/60 focus:border-forest-500 focus:bg-white focus:ring-2 focus:ring-forest-100 focus:outline-none">
    </label>

    <div class="grid gap-3 sm:grid-cols-3">
        <select name="region" class="{{ $field }}">
            <option value="">All regions</option>
            @foreach($regions as $region)
                <option value="{{ $region }}" @selected($filters['region'] === $region)>{{ $region }}</option>
            @endforeach
        </select>

        <select name="species" class="{{ $field }}">
            <option value="">All species</option>
            @foreach($speciesList as $sp)
                <option value="{{ $sp->slug }}" @selected($filters['speciesSlug'] === $sp->slug)>{{ $sp->common_name }}</option>
            @endforeach
        </select>

        <select name="market" class="{{ $field }}">
            <option value="">All export markets</option>
            @foreach($markets as $code)
                <option value="{{ $code }}" @selected($filters['market'] === $code)>{{ $code }}</option>
            @endforeach
        </select>
    </div>

    <div class="flex items-center gap-2">
        <button type="submit" class="inline-flex items-center gap-1.5 rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">
            <x-heroicon-m-funnel class="h-4 w-4" /> Apply filters
        </button>
        <a href="{{ route('directory') }}" class="rounded-full px-4 py-2.5 text-sm font-medium text-ink-soft dark:text-[#b3ab9b] transition hover:text-forest-700">Reset</a>
    </div>
</form>
