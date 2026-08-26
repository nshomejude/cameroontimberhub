{{--
    ORDER DOCUMENTS card.

    The list is LIVE — it renders `$order->documents`, so a paper the supplier
    attaches after this card was posted appears in the card that is already in
    the thread. Nothing about the list is snapshotted.

    Every row is a real uploaded file: real name, real byte count, real upload
    date. The mockup's five named PDFs (commercial invoice, packing list,
    fumigation certificate, bill of lading, insurance certificate) are NOT
    hard-coded — the platform issues none of those papers and verifies none of
    them, so listing them as though it did would be a fabrication. What the
    supplier actually uploaded is what appears; an order with no documents shows
    the empty state, not five placeholder rows.

    Each link goes through OrderDocumentDownloadController. The files sit on the
    private `documents` disk outside the web root, so there is no URL that
    reaches them without passing that controller's participation check.

    The mockup's "Download All" (a zip) is absent: no archiving was added, and
    a button that downloads one file while claiming to download all would be
    worse than no button.
--}}
@php
    /** @var \App\Models\Order|null $order */
    $order = $message->related;

    $documents = $order ? $order->documents : collect();
@endphp

@if ($order)
    <div class="py-1" id="m{{ $message->getKey() }}">
        <div class="mb-2 flex items-center gap-3">
            <span class="h-px flex-1 bg-sand-300"></span>
            <span class="text-[0.875rem] font-bold uppercase tracking-[0.14em] text-ink-soft">Order documents</span>
            <span class="h-px flex-1 bg-sand-300"></span>
        </div>

        <div class="rounded-2xl border border-sand-200 bg-white p-4 shadow-sm">
            <div class="flex items-center gap-2.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-forest-700 text-white">
                    <x-heroicon-o-folder class="h-5 w-5" />
                </span>
                <p class="min-w-0 flex-1 font-display text-[1.125rem] font-bold text-forest-950">
                    Documents for {{ $message->payloadValue('reference_code') }}
                </p>
                <span class="shrink-0 text-[0.9375rem] font-semibold text-ink-soft">
                    {{ trans_choice('{0}No documents|{1}1 document|[2,*]:count documents', $documents->count(), ['count' => $documents->count()]) }}
                </span>
            </div>

            @if ($documents->isNotEmpty())
                <ul class="mt-3 divide-y divide-sand-200 border-t border-sand-200">
                    @foreach ($documents as $document)
                        <li>
                            <a href="{{ route('order-documents.download', $document) }}"
                               class="flex items-center gap-3 py-2.5 transition hover:bg-sand-50">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-sand-100 text-[0.5625rem] font-bold text-ink-soft">
                                    {{ $document->extension() }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[1.0625rem] font-medium text-ink">{{ $document->displayName() }}</span>
                                    <span class="block text-[0.875rem] text-ink-soft">
                                        {{ $document->kind->label() }} · {{ $document->humanSize() }} · {{ $document->created_at->isoFormat('D MMM YYYY') }}
                                    </span>
                                </span>
                                <x-heroicon-m-arrow-down-tray class="h-4 w-4 shrink-0 text-forest-700" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-3 border-t border-sand-200 pt-3 text-[1.0625rem] text-ink-soft">
                    No documents have been attached to this order yet.
                </p>
            @endif

            <p class="mt-3 rounded-xl bg-sand-50 p-3 text-[0.9375rem] leading-relaxed text-ink-soft">
                These files were uploaded by the supplier. Cameroon Timber Hub does not issue,
                check or certify them. Only you and the supplier can open them.
            </p>

            <p class="mt-2 text-right text-[0.875rem] text-ink-soft">{{ $message->created_at->format('g:i A') }}</p>
        </div>

        {{-- ------------------------------------------------ supplier upload --}}
        {{--
            A plain multipart form posting to the CSRF-protected route rather
            than a Livewire upload: it degrades to a native submit with
            JavaScript off, and the file never sits in a temp area waiting for a
            second round trip. The service re-validates MIME type and size
            regardless of what these attributes say.
        --}}
        @if (! $isBuyer)
            <form method="POST" action="{{ route('chat.order.documents', [$conversation, $order]) }}"
                  enctype="multipart/form-data"
                  class="mt-2 rounded-2xl border border-sand-200 bg-white p-4">
                @csrf
                <p class="font-display text-[1.0625rem] font-bold text-forest-950">Attach a document</p>

                <label class="mt-2 block text-[0.9375rem] font-semibold text-ink">
                    Type
                    <select name="kind" class="mt-1 w-full rounded-xl border border-sand-300 px-2.5 py-1.5 text-[1.0625rem]">
                        @foreach (\App\Enums\OrderDocumentKind::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="mt-2 block text-[0.9375rem] font-semibold text-ink">
                    Label (optional)
                    <input type="text" name="label" maxlength="160"
                           class="mt-1 w-full rounded-xl border border-sand-300 px-2.5 py-1.5 text-[1.0625rem]">
                </label>

                <input type="file" name="documents[]" multiple required
                       accept=".pdf,.jpg,.jpeg,.png,.webp"
                       class="mt-2 block w-full text-[1.0625rem] text-ink-soft">

                <p class="mt-1 text-[0.875rem] text-ink-soft">PDF, JPG, PNG or WEBP. Up to 15 MB each.</p>

                <button type="submit" class="mt-3 w-full rounded-xl bg-forest-700 px-3.5 py-2.5 text-[1.0625rem] font-semibold text-white">
                    Attach
                </button>
            </form>
        @endif
    </div>
@endif
