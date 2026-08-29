<x-layouts.app
    title="Logistics Directory — verified Cameroon transport & logistics companies"
    description="Find logistics and transport companies in Cameroon. Trusted and Tech-enabled verification tiers, filterable by region.">

    <div class="max-w-7xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold mb-2">Logistics Directory</h1>
        <p class="text-gray-600 mb-6">Find transport and logistics companies in Cameroon's timber supply chain.</p>

        <form method="get" class="flex flex-wrap gap-3 mb-8">
            <input type="text" name="region" value="{{ $region }}" placeholder="Region" class="border rounded px-3 py-2">
            <button type="submit" class="px-4 py-2 rounded bg-primary-600 text-white">Filter</button>
        </form>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @forelse ($companies as $company)
                @php($tier = $tierOf($company))
                <a href="{{ route('companies.show', $company->slug) }}" class="block border rounded p-4 hover:shadow">
                    <div class="font-semibold">{{ $company->name }}</div>
                    <div class="text-sm text-gray-500">{{ $company->type?->label() }} &middot; {{ $company->region }}</div>
                    <span @class([
                        'inline-block mt-2 text-xs font-medium px-2 py-1 rounded',
                        'bg-green-100 text-green-800' => $tier === \App\Http\Controllers\Public\LogisticsDirectoryController::TIER_TECH_ENABLED,
                        'bg-blue-100 text-blue-800' => $tier === \App\Http\Controllers\Public\LogisticsDirectoryController::TIER_TRUSTED,
                        'bg-gray-100 text-gray-600' => $tier === \App\Http\Controllers\Public\LogisticsDirectoryController::TIER_UNVERIFIED,
                    ])>{{ $tier }}</span>
                </a>
            @empty
                <p class="text-gray-500">No logistics companies match these filters yet.</p>
            @endforelse
        </div>

        <div class="mt-6">{{ $companies->links() }}</div>
    </div>

</x-layouts.app>
