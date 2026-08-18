<x-layouts.account
    title="New conversation"
    heading="New conversation"
    subheading="Start a conversation with a verified supplier.">

    <div class="mb-4">
        <a href="{{ route('account.messages') }}" class="inline-flex items-center gap-1.5 text-[0.875rem] font-semibold text-forest-700">
            <x-heroicon-m-arrow-left class="h-4 w-4" /> Back to messages
        </a>
    </div>

    <div class="max-w-3xl">
        <livewire:messaging.new-conversation />
    </div>
</x-layouts.account>
