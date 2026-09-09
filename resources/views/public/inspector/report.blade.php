<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Inspection report — {{ $inspection->timberLot?->reference_code ?? '#'.$inspection->id }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{--
        Deliberately self-contained: no Livewire, no external stylesheet or
        font fetch. This page must render and be usable purely from the
        service worker's cache (public/sw.js network-first-navigate rule)
        when the inspector has no connectivity at all — a page that depends
        on an uncached asset to render correctly would defeat the point.
    --}}
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; padding-bottom: 4rem; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #faf7f0; color: #1b2a20; }
        .wrap { max-width: 640px; margin: 0 auto; padding: 1.25rem 1rem 2rem; }
        h1 { font-size: 1.25rem; margin: 0 0 .25rem; color: #16281c; }
        .meta { font-size: .875rem; color: #5b6b60; margin-bottom: 1.5rem; }
        label { display: block; font-weight: 600; font-size: .875rem; margin: 1rem 0 .35rem; }
        input[type=text], input[type=datetime-local], input[type=number], textarea, select {
            width: 100%; padding: .65rem .75rem; border: 1px solid #cdd6cf; border-radius: .5rem;
            font-size: 1rem; background: #fff; color: #16281c;
        }
        textarea { min-height: 5rem; resize: vertical; }
        fieldset { border: none; padding: 0; margin: 1rem 0 0; }
        .result-options { display: flex; gap: .5rem; }
        .result-options label { flex: 1; text-align: center; border: 1px solid #cdd6cf; border-radius: .5rem; padding: .6rem; margin: 0; font-weight: 600; }
        .result-options input { position: absolute; opacity: 0; }
        .result-options label:has(input:checked) { background: #1b3425; color: #f5f0e6; border-color: #1b3425; }
        button[type=submit] {
            margin-top: 1.5rem; width: 100%; padding: .9rem; border: none; border-radius: .6rem;
            background: #1b3425; color: #f5f0e6; font-size: 1rem; font-weight: 700; cursor: pointer;
        }
        button[type=submit]:disabled { opacity: .6; }
        #flash { display: none; margin-top: 1rem; padding: .75rem 1rem; border-radius: .5rem; font-size: .9rem; }
        #flash.ok { display: block; background: #dcecdf; color: #16401f; }
        #flash.queued { display: block; background: #fdf1da; color: #6b4a12; }
        #flash.error { display: block; background: #fbdede; color: #6b1414; }
    </style>
</head>
<body>
    <x-offline-banner />

    <div class="wrap">
        <h1>Inspection report</h1>
        <p class="meta">
            {{ $inspection->inspection_type }}
            @if ($inspection->timberLot) &middot; Lot {{ $inspection->timberLot->reference_code ?? $inspection->timberLot->id }} @endif
            @if ($inspection->scheduled_for) &middot; Scheduled {{ $inspection->scheduled_for->toDateString() }} @endif
        </p>

        <div id="flash" role="status" aria-live="polite"></div>

        <form id="report-form" autocomplete="off">
            @csrf
            <label for="performed_at">Date &amp; time performed</label>
            <input type="datetime-local" id="performed_at" name="performed_at" required
                value="{{ old('performed_at', $inspection->performed_at?->format('Y-m-d\TH:i')) }}">

            <label for="location">Location</label>
            <input type="text" id="location" name="location" placeholder="e.g. Yard 3, Douala depot"
                value="{{ old('location', $inspection->location) }}">

            <label for="observed_quantity">Observed quantity</label>
            <input type="number" step="0.01" min="0" id="observed_quantity" name="observed_quantity"
                value="{{ old('observed_quantity', $inspection->observed_quantity) }}">

            <label for="species_findings">Species findings</label>
            <textarea id="species_findings" name="species_findings">{{ old('species_findings', $inspection->species_findings) }}</textarea>

            <label for="quality_findings">Quality findings</label>
            <textarea id="quality_findings" name="quality_findings">{{ old('quality_findings', $inspection->quality_findings) }}</textarea>

            <label for="packaging_findings">Packaging findings</label>
            <textarea id="packaging_findings" name="packaging_findings">{{ old('packaging_findings', $inspection->packaging_findings) }}</textarea>

            <fieldset>
                <label>Result</label>
                <div class="result-options">
                    <label><input type="radio" name="result" value="pass" required> Pass</label>
                    <label><input type="radio" name="result" value="conditional"> Conditional</label>
                    <label><input type="radio" name="result" value="fail"> Fail</label>
                </div>
            </fieldset>

            <label for="inspector_notes">Inspector notes</label>
            <textarea id="inspector_notes" name="inspector_notes">{{ old('inspector_notes', $inspection->inspector_notes) }}</textarea>

            <button type="submit" id="submit-btn">Submit report</button>
        </form>
    </div>

    {{-- Loaded as a plain script (not `type="module" import`): Vite's
         production build drops the `export` from this file since nothing
         in the main app bundle imports it, keeping only the
         `window.OfflineQueue` side-effect assignment it also sets. A
         module-level `import { OfflineQueue } from '...'` against the
         built asset therefore throws "does not provide an export named
         OfflineQueue" in production (it only ever worked in local/dev
         where Vite serves the untouched source). The global is reliable
         in both environments, so use that instead. --}}
    <script src="{{ Vite::asset('resources/js/offline-queue.js') }}"></script>
    <script type="module">
        const { OfflineQueue } = window;

        const form = document.getElementById('report-form');
        const flash = document.getElementById('flash');
        const submitBtn = document.getElementById('submit-btn');
        const submitUrl = @json($submitUrl);
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        function showFlash(kind, message) {
            flash.className = kind;
            flash.textContent = message;
        }

        function formDataAsObject() {
            const fd = new FormData(form);
            const obj = {};
            for (const [key, value] of fd.entries()) {
                if (key === '_token') continue;
                obj[key] = value;
            }
            return obj;
        }

        // If we're still on this page when a previously-queued submission
        // for this exact inspection finally syncs, tell the inspector.
        OfflineQueue.onSync((evt) => {
            if (evt.url === submitUrl) {
                showFlash('ok', 'Synced — your queued report has now been saved.');
            }
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            submitBtn.disabled = true;
            const payload = formDataAsObject();

            const attemptLiveSubmit = navigator.onLine;

            if (attemptLiveSubmit) {
                try {
                    const resp = await fetch(submitUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify(payload),
                    });

                    if (resp.ok) {
                        showFlash('ok', 'Report submitted and finalised.');
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Submitted';
                        return;
                    }

                    if (resp.status >= 400 && resp.status < 500) {
                        // A real server-side rejection (validation, already
                        // finalised, etc.) — not a connectivity problem, so
                        // surface it instead of silently queuing forever.
                        const body = await resp.json().catch(() => ({}));
                        showFlash('error', body.message || 'This report could not be submitted. Please check the form and try again.');
                        submitBtn.disabled = false;
                        return;
                    }
                    // 5xx: fall through to queuing below.
                } catch (e) {
                    // Network error mid-flight: fall through to queuing.
                }
            }

            // Offline, or the live attempt failed for a connectivity/server
            // reason: queue it so it replays automatically once online.
            await OfflineQueue.enqueue(submitUrl, 'POST', payload, {
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            });
            showFlash('queued', "Saved — will sync when you're back online.");
            submitBtn.disabled = false;
        });
    </script>
</body>
</html>
