@props(['id' => 'site-search'])

{{--
    Header instant search (ARIA 1.2 combobox + listbox). Fetches
    GET /api/v1/search/suggest debounced at 250ms, aborting the in-flight
    request on each keystroke. Enter with no option highlighted (or without JS)
    submits the plain GET form to the full /search page.
--}}
<form action="{{ url('/search') }}" method="GET" role="search"
      {{ $attributes->merge(['class' => 'relative']) }}
      x-data="{
          q: '', open: false, loading: false, active: -1, items: [], groups: [], ctrl: null, timer: null,
          endpoint: @js(route('api.v1.search.suggest')),
          labels: @js(['products' => __('messages.search_suggest.products'), 'suppliers' => __('messages.search_suggest.suppliers'), 'species' => __('messages.search_suggest.species')]),
          input() {
              clearTimeout(this.timer);
              if (this.q.trim().length < 2) { this.ctrl?.abort(); this.reset(); return; }
              this.timer = setTimeout(() => this.fetch(), 250);
          },
          async fetch() {
              this.ctrl?.abort();
              this.ctrl = new AbortController();
              this.loading = true;
              try {
                  const res = await fetch(this.endpoint + '?q=' + encodeURIComponent(this.q.trim()), { headers: { Accept: 'application/json' }, signal: this.ctrl.signal });
                  if (! res.ok) { return; }
                  const data = (await res.json()).data;
                  this.items = []; this.groups = [];
                  for (const type of ['products', 'species', 'suppliers']) {
                      const rows = (data[type] || []).map(r => ({ type, label: r.name, sub: type === 'products' ? [r.species, r.company_name].filter(Boolean).join(' · ') : (type === 'species' ? r.scientific_name : r.city), url: r.url, img: r.image_url || r.logo_url || null, idx: this.items.length }));
                      rows.forEach((r, i) => r.idx = this.items.length + i);
                      this.items.push(...rows);
                      if (rows.length) { this.groups.push({ type, label: this.labels[type], rows }); }
                  }
                  this.active = -1;
                  this.open = true;
              } catch (e) { /* aborted or offline: keep the plain form usable */ }
              finally { this.loading = false; }
          },
          move(step) {
              if (! this.open || ! this.items.length) { return; }
              this.active = (this.active + step + this.items.length) % this.items.length;
          },
          enter(e) {
              if (this.open && this.active >= 0) { e.preventDefault(); window.location.href = this.items[this.active].url; }
          },
          reset() { this.open = false; this.items = []; this.groups = []; this.active = -1; },
      }"
      @click.outside="open = false" @keydown.escape="open = false; active = -1">
    <label for="{{ $id }}" class="sr-only">{{ __('messages.home.search_label') }}</label>
    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink/50" />
    <input id="{{ $id }}" name="q" type="search" autocomplete="off" x-model="q" @input="input()"
           @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter="enter($event)"
           @focus="if (items.length) open = true"
           role="combobox" aria-autocomplete="list" aria-controls="{{ $id }}-listbox"
           :aria-expanded="open ? 'true' : 'false'"
           :aria-activedescendant="active >= 0 ? '{{ $id }}-opt-' + active : null"
           placeholder="{{ __('messages.home.search_placeholder') }}"
           class="w-full rounded-lg border border-sand-200 bg-sand-50 py-2 pl-9 pr-3 text-sm text-ink placeholder:text-ink/50 focus:border-forest-700 focus:bg-white focus:outline-none focus:ring-1 focus:ring-forest-700">

    <div x-show="open" x-cloak
         class="absolute left-0 right-0 top-full z-50 mt-1 max-h-[70vh] overflow-y-auto rounded-xl border border-sand-200 bg-white py-1 shadow-lg">
        <ul id="{{ $id }}-listbox" role="listbox" aria-label="{{ __('messages.search_suggest.results') }}">
            <template x-for="group in groups" :key="group.type">
                <li role="presentation">
                    <div class="px-3 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-ink/50" x-text="group.label" role="presentation"></div>
                    <ul role="group" :aria-label="group.label">
                        <template x-for="row in group.rows" :key="row.url">
                            <li role="option" :id="'{{ $id }}-opt-' + row.idx" :aria-selected="active === row.idx ? 'true' : 'false'"
                                @mouseenter="active = row.idx" @mousedown.prevent="window.location.href = row.url"
                                :class="active === row.idx ? 'bg-sand-100' : ''"
                                class="flex cursor-pointer items-center gap-3 px-3 py-2">
                                <template x-if="row.img"><img :src="row.img" alt="" class="h-8 w-8 shrink-0 rounded object-cover" loading="lazy"></template>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm text-ink" x-text="row.label"></span>
                                    <span class="block truncate text-xs text-ink/60" x-show="row.sub" x-text="row.sub"></span>
                                </span>
                            </li>
                        </template>
                    </ul>
                </li>
            </template>
        </ul>
        <p x-show="! loading && ! items.length" class="px-3 py-2 text-sm text-ink/60">{{ __('messages.search_suggest.none') }}</p>
        <button type="submit" class="block w-full border-t border-sand-200 px-3 py-2 text-left text-sm font-medium text-forest-700 hover:bg-sand-100">
            {{ __('messages.search_suggest.see_all') }} “<span x-text="q.trim()"></span>”
        </button>
    </div>
    <div class="sr-only" role="status" aria-live="polite" x-text="open ? items.length + ' ' + {{ \Illuminate\Support\Js::from(__('messages.search_suggest.results')) }} : ''"></div>
</form>
