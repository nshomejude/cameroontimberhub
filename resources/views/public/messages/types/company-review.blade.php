{{--
    COMPANY REVIEW card — the review the buyer left, shown in the thread.

    `related` is the CompanyReview, so the live half is the moderation status:
    if a review is later taken down, this card stops showing its content rather
    than leaving removed text sitting in the conversation.

    Stored XSS is the obvious risk on this card, since the body is the one piece
    of free text on the platform that a buyer writes and a stranger reads. It is
    rendered with `{{ }}` — Blade's escaping — and never with `{!! !!}`. There
    is no markdown pass, no nl2br() on raw input and no HTML purifier, because
    escaping is the correct defence and anything that unescapes to "render
    formatting" would reintroduce exactly the hole. Line breaks are handled by
    CSS (`whitespace-pre-line`), which needs no markup at all.
--}}
@php
    /** @var \App\Models\CompanyReview|null $review */
    $review = $message->related;
@endphp

@if ($review && $review->isPublished())
    <div class="py-1" id="m{{ $message->getKey() }}">
        <div class="mb-2 flex items-center gap-3">
            <span class="h-px flex-1 bg-sand-300"></span>
            <span class="text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-ink-soft">Review published</span>
            <span class="h-px flex-1 bg-sand-300"></span>
        </div>

        <div class="rounded-2xl border border-sand-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-start gap-2.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-500 text-white">
                    <x-heroicon-m-star class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="font-display text-[0.9375rem] font-bold text-forest-950">
                        {{ $review->rating }} out of 5
                    </p>
                    <p class="text-[0.75rem] text-ink-soft">
                        by {{ $review->author_name }}
                        @if ($review->created_at)
                            · {{ $review->created_at->isoFormat('D MMM YYYY') }}
                        @endif
                    </p>
                </div>
            </div>

            @if ($review->title)
                <p class="mt-3 border-t border-sand-200 pt-3 text-[0.875rem] font-semibold text-ink">{{ $review->title }}</p>
            @endif

            @if ($review->body)
                {{-- Escaped by Blade. `whitespace-pre-line` preserves the
                     buyer's line breaks without any markup being emitted. --}}
                <p @class(['whitespace-pre-line text-[0.8125rem] leading-relaxed text-ink', 'mt-3 border-t border-sand-200 pt-3' => ! $review->title, 'mt-1.5' => (bool) $review->title])>{{ $review->body }}</p>
            @endif

            <p class="mt-3 text-[0.6875rem] text-ink-soft">
                Left after a completed order on this platform.
            </p>

            <p class="mt-2 text-right text-[0.6875rem] text-ink-soft">{{ $message->created_at->format('g:i A') }}</p>
        </div>
    </div>
@endif
