<x-layouts.app
    :title="$page->title"
    :description="$page->meta_description">

    <section class="border-b border-sand-200 dark:border-[#2c2a24] bg-gradient-to-b from-forest-50 dark:from-forest-950 to-sand-50 dark:to-[#14130f]">
        <div class="mx-auto max-w-3xl px-4 py-14">
            <h1 class="font-display text-4xl font-semibold text-forest-950 dark:text-sand-100 sm:text-5xl">
                {{ $page->h1 ?: $page->title }}
            </h1>
            @if($page->meta_description)
                <p class="mt-4 text-lg text-ink-soft dark:text-[#b3ab9b]">{{ $page->meta_description }}</p>
            @endif
        </div>
    </section>

    <div class="mx-auto max-w-3xl px-4 py-12">

        @if(session('contact_sent'))
            <div class="mb-8 rounded-2xl bg-forest-50 dark:bg-forest-950 border border-forest-200 dark:border-forest-800 p-6">
                <p class="font-medium text-forest-800 dark:text-forest-200">Message sent — we'll be in touch soon.</p>
            </div>
        @endif

        @if(is_array($page->data) && !empty($page->data['blocks']))
            @foreach($page->data['blocks'] as $block)
                <p class="mb-6 text-ink-soft dark:text-[#b3ab9b]">{{ $block['content'] ?? '' }}</p>
            @endforeach
        @endif

        <form method="POST" action="{{ route('contact.store') }}" class="space-y-6">
            @csrf

            {{-- Honeypot --}}
            <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off">
            <input type="hidden" name="form_rendered_at" value="{{ now()->timestamp }}">

            <div class="grid gap-6 sm:grid-cols-2">
                <div>
                    <label for="name" class="block text-sm font-medium text-forest-900 dark:text-sand-100">Name</label>
                    <input id="name" name="name" type="text" value="{{ old('name') }}" required
                        class="mt-1.5 block w-full rounded-xl border border-sand-300 dark:border-[#3a352e] bg-white dark:bg-[#1f1d18] px-4 py-2.5 text-sm text-ink dark:text-[#f1ece1] focus:border-forest-400 focus:outline-none focus:ring-1 focus:ring-forest-400">
                    @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-forest-900 dark:text-sand-100">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required
                        class="mt-1.5 block w-full rounded-xl border border-sand-300 dark:border-[#3a352e] bg-white dark:bg-[#1f1d18] px-4 py-2.5 text-sm text-ink dark:text-[#f1ece1] focus:border-forest-400 focus:outline-none focus:ring-1 focus:ring-forest-400">
                    @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label for="subject" class="block text-sm font-medium text-forest-900 dark:text-sand-100">Subject</label>
                <input id="subject" name="subject" type="text" value="{{ old('subject') }}" required
                    class="mt-1.5 block w-full rounded-xl border border-sand-300 dark:border-[#3a352e] bg-white dark:bg-[#1f1d18] px-4 py-2.5 text-sm text-ink dark:text-[#f1ece1] focus:border-forest-400 focus:outline-none focus:ring-1 focus:ring-forest-400">
                @error('subject')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="message" class="block text-sm font-medium text-forest-900 dark:text-sand-100">Message</label>
                <textarea id="message" name="message" rows="6" required
                    class="mt-1.5 block w-full rounded-xl border border-sand-300 dark:border-[#3a352e] bg-white dark:bg-[#1f1d18] px-4 py-2.5 text-sm text-ink dark:text-[#f1ece1] focus:border-forest-400 focus:outline-none focus:ring-1 focus:ring-forest-400">{{ old('message') }}</textarea>
                @error('message')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="flex items-start gap-3">
                <input id="consent" name="consent" type="checkbox" value="1" class="mt-0.5 h-4 w-4 rounded border-sand-300 text-forest-600 focus:ring-forest-400">
                <label for="consent" class="text-sm text-ink-soft dark:text-[#b3ab9b]">
                    I agree that Cameroon Timber Hub may use this information to respond to my message.
                </label>
            </div>
            @error('consent')<p class="text-xs text-red-600">{{ $message }}</p>@enderror

            <button type="submit"
                class="inline-flex items-center gap-1.5 rounded-full bg-forest-700 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800">
                Send message <x-heroicon-m-paper-airplane class="h-4 w-4" />
            </button>
        </form>
    </div>
</x-layouts.app>
