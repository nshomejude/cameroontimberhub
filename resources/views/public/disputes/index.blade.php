<x-layouts.app :title="__('messages.dispute.page_title')" noindex>
    <div class="mx-auto max-w-[900px] px-4 py-8 lg:px-6">
        <h1 class="font-display text-2xl font-bold text-forest-950">{{ __('messages.dispute.heading_order', ['ref' => $order->reference_code ?? '#'.$order->id]) }}</h1>
        <p class="mt-1 text-sm text-ink-soft">{{ __('messages.dispute.intro') }}</p>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-forest-100 px-4 py-3 text-sm text-forest-800">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="mt-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        <section class="mt-8">
            <h2 class="text-lg font-semibold text-forest-950">{{ __('messages.dispute.existing') }}</h2>

            @if ($disputes->isEmpty())
                <p class="mt-2 text-sm text-ink-soft">{{ __('messages.dispute.none_on_order') }}</p>
            @else
                <ul class="mt-3 divide-y divide-sand-200 rounded-2xl border border-sand-200 bg-white">
                    @foreach ($disputes as $dispute)
                        <li class="p-4">
                            <a href="{{ route('disputes.show', ['order' => $order->id, 'dispute' => $dispute->id]) }}"
                               class="flex items-center justify-between">
                                <span class="font-semibold text-forest-700">#{{ $dispute->id }} — {{ $dispute->category->label() }}</span>
                                <span class="rounded-full bg-{{ $dispute->status->color() }}-100 px-3 py-1 text-xs font-semibold text-{{ $dispute->status->color() }}-800">
                                    {{ $dispute->status->label() }}
                                </span>
                            </a>
                            <p class="mt-1 text-sm text-ink-soft">{{ Str::limit($dispute->description, 140) }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="mt-8">
            <h2 class="text-lg font-semibold text-forest-950">{{ __('messages.dispute.open_new') }}</h2>
            <form method="POST" action="{{ route('disputes.store', ['order' => $order->id]) }}" class="mt-3 space-y-4 rounded-2xl border border-sand-200 bg-white p-5">
                @csrf

                <div>
                    <label for="category" class="block text-sm font-semibold text-ink">{{ __('messages.dispute.category') }}</label>
                    <select id="category" name="category" class="mt-1 w-full rounded-lg border-sand-300">
                        @foreach ($categories as $value => $label)
                            <option value="{{ $value }}" @selected(old('category') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('category') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="description" class="block text-sm font-semibold text-ink">{{ __('messages.dispute.what_happened') }}</label>
                    <textarea id="description" name="description" rows="5" class="mt-1 w-full rounded-lg border-sand-300">{{ old('description') }}</textarea>
                    @error('description') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="rounded-lg bg-forest-700 px-4 py-2 font-semibold text-white hover:bg-forest-800">
                    {{ __('messages.dispute.open_dispute') }}
                </button>
            </form>
        </section>
    </div>
</x-layouts.app>
