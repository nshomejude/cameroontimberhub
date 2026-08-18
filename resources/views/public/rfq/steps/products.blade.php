@php
    use App\Services\RfqWizard;

    $rows = $wizard->editableItems();
    $speciesOptions = $species->mapWithKeys(fn ($s) => [$s->id => $s->common_name])->all();
    $formOptions = collect($forms)->mapWithKeys(fn ($f) => [$f->value => $f->label()])->all();
    $unitOptions = collect($units)->mapWithKeys(fn ($u) => [$u->value => $u->label()])->all();
@endphp

<fieldset class="space-y-5">
    <legend class="sr-only">Products and requirements</legend>

    @if ($shortlist->isNotEmpty())
        <div class="rounded-xl border border-forest-200 bg-forest-50 px-4 py-4 dark:border-forest-900 dark:bg-forest-950">
            <h3 class="flex items-center gap-2 text-[0.875rem] font-bold text-forest-900 dark:text-forest-200">
                <x-heroicon-o-clipboard-document-list class="h-4 w-4" />
                From your RFQ list ({{ $shortlist->count() }})
            </h3>
            <p class="mt-1 text-[0.8125rem] text-forest-800 dark:text-forest-300">
                These shortlisted listings pre-filled the products below. Remove any you no longer need.
            </p>
            <ul class="mt-3 space-y-2">
                @foreach ($shortlist as $listed)
                    <li class="flex items-center gap-3 rounded-lg bg-white px-3 py-2 dark:bg-[#1f1d18]">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('products.show', $listed->slug) }}"
                               class="block truncate text-[0.8125rem] font-semibold text-ink hover:text-forest-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 dark:text-[#e4ddcf]">{{ $listed->name }}</a>
                            <p class="truncate text-[0.75rem] text-ink-soft dark:text-[#8f887b]">{{ $listed->company?->name }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="space-y-4">
        @foreach ($rows as $i => $row)
            <fieldset class="rounded-xl border border-sand-200 px-4 py-4 dark:border-[#3a352e]">
                <legend class="px-1 text-[0.8125rem] font-bold text-forest-800 dark:text-forest-300">
                    Product {{ $i + 1 }}
                </legend>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-rfq.field :name="'items['.$i.'][species_id]'" label="Species (from the catalogue)" type="select"
                                 :value="$row['species_id'] ?? null"
                                 :autofocus="$loop->first && ! $errors->any()"
                                 placeholder="— choose, or type one below —" :options="$speciesOptions" />

                    <x-rfq.field :name="'items['.$i.'][species_text]'" label="Species (free text)"
                                 :value="$row['species_text'] ?? null"
                                 placeholder="e.g. Sapele" maxlength="160"
                                 help="Only needed if the species is not in the catalogue." />

                    <x-rfq.field :name="'items['.$i.'][form]'" label="Product form" type="select" required
                                 :value="$row['form'] ?? null"
                                 placeholder="— select a form —" :options="$formOptions" />

                    <div class="grid grid-cols-2 gap-3">
                        <x-rfq.field :name="'items['.$i.'][quantity]'" label="Quantity" type="number" required
                                     step="0.01" min="0" :value="$row['quantity'] ?? null" />
                        <x-rfq.field :name="'items['.$i.'][unit]'" label="Unit" type="select" required
                                     :value="$row['unit'] ?? null" :options="$unitOptions" />
                    </div>

                    <x-rfq.field :name="'items['.$i.'][grade]'" label="Grade"
                                 :value="$row['grade'] ?? null" placeholder="e.g. FAS, Grade A" maxlength="60" />

                    <x-rfq.field :name="'items['.$i.'][dimensions]'" label="Dimensions"
                                 :value="$row['dimensions'] ?? null" placeholder="e.g. 50 x 150 x 3000 mm" maxlength="160" />

                    <x-rfq.field :name="'items['.$i.'][moisture_content]'" label="Moisture content"
                                 :value="$row['moisture_content'] ?? null" placeholder="e.g. KD 12%" maxlength="60" />
                </div>

                @if (count($rows) > 1)
                    <button type="submit" name="remove_item" value="{{ $i }}" formnovalidate
                            class="mt-3 inline-flex items-center gap-1.5 rounded-full border border-sand-300 px-3.5 py-1.5 text-[0.75rem] font-semibold text-ink-soft transition hover:border-red-300 hover:text-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2 dark:border-[#3a352e] dark:text-[#8f887b]">
                        <x-heroicon-m-trash class="h-3.5 w-3.5" /> Remove product {{ $i + 1 }}
                    </button>
                @endif
            </fieldset>
        @endforeach
    </div>

    @if (count($rows) < RfqWizard::MAX_ITEMS)
        <button type="submit" name="add_item" value="1" formnovalidate
                class="inline-flex items-center gap-2 rounded-full border border-dashed border-forest-300 px-5 py-2.5 text-[0.875rem] font-semibold text-forest-800 transition hover:bg-forest-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-forest-500 focus-visible:ring-offset-2 dark:border-forest-800 dark:text-forest-300 dark:hover:bg-forest-950">
            <x-heroicon-m-plus class="h-4 w-4" /> Add another product
        </button>
    @else
        <p class="text-[0.8125rem] text-ink-soft dark:text-[#8f887b]">
            You can request up to {{ RfqWizard::MAX_ITEMS }} products in one RFQ.
        </p>
    @endif
</fieldset>
