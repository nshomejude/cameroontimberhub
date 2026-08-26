@props(['product', 'breadcrumbs' => [], 'inRfqList' => false])

@php
    $images = $product->galleryImages();
    $count = count($images);
    // The "Video" tile only exists when a real video URL is recorded.
    $hasVideo = filled($product->video_url);
    // The strip is worth showing when there is more than one image to choose
    // between, or when a real video tile has to sit beside the photo.
    $showStrip = $count > 1 || $hasVideo;
@endphp

{{-- Dark forest band: breadcrumb + gallery (mobile design) --}}
<section class="rounded-b-[1.75rem] bg-forest-950 px-4 pb-5 pt-3">
    <nav aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[1.0625rem] text-forest-100">
            @foreach ($breadcrumbs as $i => $crumb)
                <li class="flex items-center gap-2">
                    @if ($i > 0)
                        <span aria-hidden="true" class="text-forest-300">&rsaquo;</span>
                    @endif

                    @if ($i === count($breadcrumbs) - 1)
                        <span aria-current="page" class="font-semibold text-white">{{ $crumb['label'] }}</span>
                    @elseif ($i === 0)
                        <a href="{{ $crumb['url'] }}"
                           class="rounded text-white transition hover:text-forest-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
                           aria-label="{{ $crumb['label'] }}">
                            <x-heroicon-o-home class="h-[1.125rem] w-[1.125rem]" />
                        </a>
                    @else
                        <a href="{{ $crumb['url'] }}"
                           class="rounded transition hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white">{{ $crumb['label'] }}</a>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>

    <div class="mt-3"
         x-data="{
            active: 0,
            total: {{ max($count, 1) }},
            go(i) { this.active = (i + this.total) % this.total; },
            focusThumb(i) { const el = this.$refs['thumb-' + i]; if (el) el.focus(); },
            share() {
                const data = { title: @js($product->name), url: window.location.href };
                if (navigator.share) { navigator.share(data).catch(() => {}); return; }
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(window.location.href)
                        .then(() => { this.copied = true; setTimeout(() => this.copied = false, 2000); })
                        .catch(() => {});
                    return;
                }
                window.prompt('Copy this link', window.location.href);
            },
            copied: false,
         }">
        <div class="relative overflow-hidden rounded-2xl bg-forest-900">
            @forelse ($images as $i => $image)
                <img src="{{ $image['url'] }}"
                     alt="{{ $i === 0 ? $product->name : ($image['alt'] ?: '') }}"
                     width="960" height="720"
                     x-show="active === {{ $i }}" @if ($i > 0) x-cloak @endif
                     class="aspect-[4/3] w-full object-cover">
            @empty
                <div class="flex aspect-[4/3] w-full items-center justify-center text-forest-300" aria-hidden="true">
                    <x-heroicon-o-photo class="h-14 w-14" />
                </div>
            @endforelse

            @if ($product->is_best_seller)
                <span class="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded-lg bg-forest-800/95 px-3 py-1.5 text-[1.0625rem] font-semibold text-white ring-1 ring-white/20">
                    <x-heroicon-o-star class="h-4 w-4" />
                    Best Seller
                </span>
            @endif

            <div class="absolute right-3 top-3 flex items-center gap-2">
                <form method="POST" action="{{ route('rfq-list.store', $product->slug) }}">
                    @csrf
                    <button type="submit"
                            class="flex h-11 w-11 items-center justify-center rounded-full bg-white shadow-md transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-forest-950 {{ $inRfqList ? 'text-forest-700' : 'text-ink' }}"
                            aria-pressed="{{ $inRfqList ? 'true' : 'false' }}"
                            aria-label="{{ $inRfqList ? 'Remove '.$product->name.' from your RFQ list' : 'Add '.$product->name.' to your RFQ list' }}">
                        <x-dynamic-component :component="$inRfqList ? 'heroicon-s-heart' : 'heroicon-o-heart'" class="h-5 w-5" />
                    </button>
                </form>

                <button type="button" @click="share()"
                        class="flex h-11 w-11 items-center justify-center rounded-full bg-white text-ink shadow-md transition hover:text-forest-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-forest-950"
                        aria-label="Share this product">
                    <x-heroicon-o-share class="h-5 w-5" />
                </button>
            </div>

            <p x-show="copied" x-cloak role="status"
               class="absolute inset-x-3 bottom-3 rounded-lg bg-forest-800/95 px-3 py-2 text-center text-[1.0625rem] font-semibold text-white">
                Link copied
            </p>
        </div>

        @if ($showStrip)
            <ul class="mt-3 flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                role="list" aria-label="Product images">
                @foreach ($images as $i => $image)
                    <li class="shrink-0">
                        <button type="button"
                                x-ref="thumb-{{ $i }}"
                                @click="active = {{ $i }}"
                                @keydown.arrow-right.prevent="go({{ $i }} + 1); focusThumb(active)"
                                @keydown.arrow-left.prevent="go({{ $i }} - 1); focusThumb(active)"
                                :aria-pressed="active === {{ $i }} ? 'true' : 'false'"
                                aria-pressed="{{ $i === 0 ? 'true' : 'false' }}"
                                :class="active === {{ $i }} ? 'border-timber-300 ring-2 ring-timber-300/40' : 'border-white/25 hover:border-white/60'"
                                class="block h-[4.5rem] w-[5.5rem] overflow-hidden rounded-xl border-2 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
                                aria-label="Show image {{ $i + 1 }} of {{ $count }}">
                            <img src="{{ $image['url'] }}" alt="" loading="lazy" width="200" height="150"
                                 class="h-full w-full object-cover">
                        </button>
                    </li>
                @endforeach

                @if ($hasVideo)
                    <li class="shrink-0">
                        <a href="{{ $product->video_url }}" target="_blank" rel="noopener noreferrer"
                           class="flex h-[4.5rem] w-[5.5rem] flex-col items-center justify-center gap-1 rounded-xl border-2 border-white/25 bg-forest-900 text-white transition hover:border-white/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white">
                            <x-heroicon-s-play-circle class="h-6 w-6" />
                            <span class="text-[0.9375rem] font-semibold">Video</span>
                        </a>
                    </li>
                @endif
            </ul>
        @endif
    </div>
</section>
