<x-layouts.app
    title="Transformation Network — verified Cameroon processors & manufacturers"
    description="Find verified timber processors and manufacturers in Cameroon. Filter by capability, region and species.">

    <div class="max-w-7xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold mb-2">Transformation Network</h1>
        <p class="text-gray-600 mb-6">Find verified processors and manufacturers in Cameroon's timber transformation sector.</p>

        <div class="flex flex-wrap gap-3 mb-6">
            <a href="{{ route('transformation-network', ['type' => 'processor']) }}"
                class="px-4 py-2 rounded border {{ $type === 'processor' ? 'bg-primary-600 text-white' : 'bg-white' }}">
                Find a Processor
            </a>
            <a href="{{ route('transformation-network', ['type' => 'manufacturer']) }}"
                class="px-4 py-2 rounded border {{ $type === 'manufacturer' ? 'bg-primary-600 text-white' : 'bg-white' }}">
                Find a Manufacturer
            </a>
            <a href="{{ route('transformation-network.match') }}" class="px-4 py-2 rounded border">
                Find a Transformer for my stock
            </a>
        </div>

        <form method="get" class="flex flex-wrap gap-3 mb-8">
            @if ($type !== '')
                <input type="hidden" name="type" value="{{ $type }}">
            @endif
            <input type="text" name="region" value="{{ $region }}" placeholder="Region" class="border rounded px-3 py-2">
            <select name="capability" class="border rounded px-3 py-2">
                <option value="">Any capability</option>
                @foreach ($businessTypes as $businessType)
                    <option value="{{ $businessType }}" @selected($capability === $businessType)>{{ $businessType }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2 rounded bg-primary-600 text-white">Filter</button>
        </form>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @forelse ($companies as $company)
                <a href="{{ route('companies.show', $company->slug) }}" class="block border rounded p-4 hover:shadow">
                    <div class="font-semibold">{{ $company->name }}</div>
                    <div class="text-sm text-gray-500">{{ $company->type?->label() }} &middot; {{ $company->region }}</div>
                </a>
            @empty
                <p class="text-gray-500">No processors or manufacturers match these filters yet.</p>
            @endforelse
        </div>

        <div class="mt-6">{{ $companies->links() }}</div>
    </div>

</x-layouts.app>
