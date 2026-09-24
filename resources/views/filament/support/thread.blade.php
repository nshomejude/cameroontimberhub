<div class="space-y-3">
    <p class="text-sm text-gray-500">
        {{ $ticket->user?->name }} &middot; {{ ucfirst($ticket->category->value) }}
        @if ($ticket->order) &middot; Order {{ $ticket->order->reference_code }} @endif
        &middot; {{ $ticket->status->label() }}
    </p>
    @foreach ($ticket->messages as $message)
        <div @class([
            'rounded-lg p-3 text-sm',
            'bg-primary-50 dark:bg-primary-950 ml-8' => $message->is_staff,
            'bg-gray-100 dark:bg-gray-800 mr-8' => ! $message->is_staff,
        ])>
            <div class="mb-1 text-xs font-semibold">
                {{ $message->user?->name ?? ($message->is_staff ? 'Support' : 'User') }}
                @if ($message->is_staff) (staff) @endif
                &middot; {{ $message->created_at?->format('Y-m-d H:i') }}
            </div>
            <div class="whitespace-pre-line">{{ $message->body }}</div>
        </div>
    @endforeach
</div>
