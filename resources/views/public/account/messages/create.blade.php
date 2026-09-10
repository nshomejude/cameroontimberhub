<x-layouts.account
    :title="__('messages.account.messages_new_conversation')"
    :heading="__('messages.account.messages_new_conversation')"
    :subheading="__('messages.account.messages_new_subheading')">

    <div class="mb-4">
        <a href="{{ route('account.messages') }}" class="inline-flex items-center gap-1.5 text-[1.0625rem] font-semibold text-forest-700">
            <x-heroicon-m-arrow-left class="h-4 w-4" /> {{ __('messages.account.messages_back') }}
        </a>
    </div>

    <div class="max-w-3xl">
        <livewire:messaging.new-conversation />
    </div>
</x-layouts.account>
