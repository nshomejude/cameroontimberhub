<x-layouts.app :title="__('messages.dispute.detail_title', ['id' => $dispute->id])" noindex>
    <div class="mx-auto max-w-[900px] px-4 py-8 lg:px-6">
        <a href="{{ route('disputes.index', ['order' => $order->id]) }}" class="text-sm text-forest-700 hover:underline">&larr; {{ __('messages.dispute.back_to_disputes') }}</a>

        <div class="mt-3 flex items-center justify-between">
            <h1 class="font-display text-2xl font-bold text-forest-950">{{ __('messages.dispute.detail_heading', ['id' => $dispute->id, 'category' => $dispute->category->label()]) }}</h1>
            <span class="rounded-full bg-{{ $dispute->status->color() }}-100 px-3 py-1 text-xs font-semibold text-{{ $dispute->status->color() }}-800">
                {{ $dispute->status->label() }}
            </span>
        </div>

        <p class="mt-2 text-sm text-ink-soft">{{ __('messages.dispute.raised_by_on', ['name' => $dispute->raisedByUser?->name ?? __('messages.dispute.a_party'), 'date' => $dispute->created_at->format('d M Y')]) }}</p>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-forest-100 px-4 py-3 text-sm text-forest-800">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="mt-4 rounded-lg bg-red-100 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        <section class="mt-6 rounded-2xl border border-sand-200 bg-white p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-soft">{{ __('messages.dispute.description') }}</h2>
            <p class="mt-2 whitespace-pre-line text-ink">{{ $dispute->description }}</p>
        </section>

        @if ($dispute->status === \App\Enums\DisputeStatus::Resolved || $dispute->status === \App\Enums\DisputeStatus::Closed)
            <section class="mt-6 rounded-2xl border border-forest-200 bg-forest-50 p-5">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-forest-800">{{ __('messages.dispute.admin_decision') }}</h2>
                <p class="mt-2 whitespace-pre-line text-ink">{{ $dispute->resolution_notes }}</p>
                @if ($dispute->status === \App\Enums\DisputeStatus::Resolved)
                    <form method="POST" action="{{ route('disputes.appeal', ['order' => $order->id, 'dispute' => $dispute->id]) }}" class="mt-3">
                        @csrf
                        <button type="submit" class="rounded-lg border border-forest-700 px-4 py-2 text-sm font-semibold text-forest-700 hover:bg-forest-100">
                            {{ __('messages.dispute.appeal_decision') }}
                        </button>
                    </form>
                @endif
            </section>
        @endif

        <section class="mt-6">
            <h2 class="text-lg font-semibold text-forest-950">{{ __('messages.dispute.evidence') }}</h2>
            <ul class="mt-2 space-y-2">
                @forelse ($dispute->evidence as $item)
                    <li class="rounded-lg border border-sand-200 bg-white p-3 text-sm">
                        <span class="font-semibold text-ink">{{ $item->submittedByUser?->name ?? __('messages.dispute.a_party_cap') }}</span>
                        <span class="text-ink-soft"> — {{ $item->created_at->format('d M Y') }}</span>
                        <p class="mt-1 text-ink">{{ $item->description }}</p>
                        @if ($item->hasFile())
                            <p class="mt-1 text-ink-soft">{{ __('messages.dispute.attached', ['file' => $item->original_filename]) }}</p>
                        @endif
                    </li>
                @empty
                    <li class="text-sm text-ink-soft">{{ __('messages.dispute.no_evidence') }}</li>
                @endforelse
            </ul>

            <form method="POST" action="{{ route('disputes.evidence', ['order' => $order->id, 'dispute' => $dispute->id]) }}"
                  enctype="multipart/form-data" class="mt-4 space-y-3 rounded-2xl border border-sand-200 bg-white p-5">
                @csrf
                <textarea name="description" rows="3" placeholder="{{ __('messages.dispute.describe_evidence') }}" class="w-full rounded-lg border-sand-300"></textarea>
                <input type="file" name="file" class="w-full text-sm">
                <button type="submit" class="rounded-lg bg-forest-700 px-4 py-2 font-semibold text-white hover:bg-forest-800">
                    {{ __('messages.dispute.submit_evidence') }}
                </button>
            </form>
        </section>

        <section class="mt-6">
            <h2 class="text-lg font-semibold text-forest-950">{{ __('messages.dispute.messages') }}</h2>
            <ul class="mt-2 space-y-2">
                @forelse ($dispute->messages as $message)
                    <li class="rounded-lg border border-sand-200 bg-white p-3 text-sm">
                        <span class="font-semibold text-ink">{{ $message->user?->name ?? __('messages.dispute.a_party_cap') }}</span>
                        <span class="text-ink-soft"> — {{ $message->created_at->format('d M Y H:i') }}</span>
                        <p class="mt-1 text-ink">{{ $message->body }}</p>
                    </li>
                @empty
                    <li class="text-sm text-ink-soft">{{ __('messages.dispute.no_messages') }}</li>
                @endforelse
            </ul>

            <form method="POST" action="{{ route('disputes.reply', ['order' => $order->id, 'dispute' => $dispute->id]) }}"
                  class="mt-4 space-y-3 rounded-2xl border border-sand-200 bg-white p-5">
                @csrf
                <textarea name="body" rows="3" placeholder="{{ __('messages.dispute.write_response') }}" class="w-full rounded-lg border-sand-300"></textarea>
                <button type="submit" class="rounded-lg bg-forest-700 px-4 py-2 font-semibold text-white hover:bg-forest-800">
                    {{ __('messages.dispute.send_response') }}
                </button>
            </form>
        </section>
    </div>
</x-layouts.app>
