<x-layouts.app
    :title="$company->name.' — Portfolio'"
    :description="'Completed work from '.$company->name.'.'">

    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <a href="{{ route('companies.show', $company->slug) }}" class="text-sm text-forest-600 hover:underline">&larr; Back to {{ $company->name }}</a>
            <h1 class="mt-2 text-2xl font-semibold text-gray-900">{{ $company->name }} — Portfolio</h1>
            <p class="mt-1 text-sm text-gray-500">Completed work from this artisan.</p>
        </div>

        @if ($items->isEmpty())
            <p class="text-sm text-gray-500">No portfolio pieces have been added yet.</p>
        @else
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($items as $item)
                    <article class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                        <img src="{{ asset('storage/'.$item->image_path) }}" alt="{{ $item->alt_text ?? $item->caption }}" class="h-48 w-full object-cover">
                        <div class="p-4">
                            <h2 class="text-base font-semibold text-gray-900">{{ $item->caption }}</h2>
                            @if ($item->description)
                                <p class="mt-1 text-sm text-gray-600">{{ $item->description }}</p>
                            @endif
                            <dl class="mt-3 space-y-1 text-xs text-gray-500">
                                @if ($item->materials_used)
                                    <div><dt class="inline font-medium">Materials:</dt> <dd class="inline">{{ $item->materials_used }}</dd></div>
                                @endif
                                @if ($item->completed_on)
                                    <div><dt class="inline font-medium">Completed:</dt> <dd class="inline">{{ $item->completed_on->format('M Y') }}</dd></div>
                                @endif
                            </dl>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
