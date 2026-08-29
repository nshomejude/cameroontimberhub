<x-layouts.app
    title="Find a Transformer — match your timber stock to a processor"
    description="Tell us what species and quantity you have and we'll match you with a verified Cameroon processor or manufacturer with the capacity to take it.">

    <div class="max-w-3xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold mb-2">Find a Transformer</h1>
        <p class="text-gray-600 mb-6">Tell us what you have and we'll match you with a processor or manufacturer who can take it.</p>

        <form method="get" action="{{ route('transformation-network.match') }}" class="flex flex-wrap gap-3 mb-8">
            <select name="species" class="border rounded px-3 py-2">
                <option value="">Select species</option>
                @foreach ($speciesOptions as $option)
                    <option value="{{ $option->slug }}" @selected($species === $option->slug)>{{ $option->common_name }}</option>
                @endforeach
            </select>
            <input type="number" step="0.01" name="quantity" value="{{ $quantity }}" placeholder="Quantity (m3)" class="border rounded px-3 py-2">
            <select name="period" class="border rounded px-3 py-2">
                @foreach (['day', 'week', 'month', 'quarter', 'year'] as $p)
                    <option value="{{ $p }}" @selected($period === $p)>per {{ $p }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2 rounded bg-primary-600 text-white">Find matches</button>
        </form>

        <div class="grid grid-cols-1 gap-4">
            @forelse ($matches as $match)
                <a href="{{ route('companies.show', $match->slug) }}" class="block border rounded p-4 hover:shadow">
                    <div class="font-semibold">{{ $match->name }}</div>
                    <div class="text-sm text-gray-500">{{ $match->type?->label() }} &middot; {{ $match->region }}</div>
                </a>
            @empty
                @if ($species && $quantity)
                    <p class="text-gray-500">No processor or manufacturer currently has enough capacity for that.</p>
                @endif
            @endforelse
        </div>
    </div>

</x-layouts.app>
