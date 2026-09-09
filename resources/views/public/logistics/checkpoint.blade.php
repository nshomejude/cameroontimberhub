<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Record checkpoint — {{ $shipment->waybill_number }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{--
        Plain Blade + fetch(), no Livewire — same reasoning as
        resources/views/public/inspector/report.blade.php: this must render
        and be usable from the field with no connectivity at all (rural
        roads, border crossings, ports), which rules out anything that needs
        a live connection to the server per interaction.
    --}}
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; padding-bottom: 4rem; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #faf7f0; color: #1b2a20; }
        .wrap { max-width: 640px; margin: 0 auto; padding: 1.25rem 1rem 2rem; }
        h1 { font-size: 1.25rem; margin: 0 0 .25rem; color: #16281c; }
        .meta { font-size: .875rem; color: #5b6b60; margin-bottom: 1.5rem; }
        label { display: block; font-weight: 600; font-size: .875rem; margin: 1rem 0 .35rem; }
        input[type=text], input[type=number], textarea {
            width: 100%; padding: .65rem .75rem; border: 1px solid #cdd6cf; border-radius: .5rem;
            font-size: 1rem; background: #fff; color: #16281c;
        }
        textarea { min-height: 5rem; resize: vertical; }
        fieldset { border: none; padding: 0; margin: 1rem 0 0; }
        .status-options { display: flex; flex-wrap: wrap; gap: .5rem; }
        .status-options label { flex: 1 1 45%; text-align: center; border: 1px solid #cdd6cf; border-radius: .5rem; padding: .6rem; margin: 0; font-weight: 600; }
        .status-options input { position: absolute; opacity: 0; }
        .status-options label:has(input:checked) { background: #1b3425; color: #f5f0e6; border-color: #1b3425; }
        .locate-row { display: flex; gap: .5rem; align-items: center; }
        .locate-row input { flex: 1; }
        button { font-family: inherit; }
        button[type=submit] {
            margin-top: 1.5rem; width: 100%; padding: .9rem; border: none; border-radius: .6rem;
            background: #1b3425; color: #f5f0e6; font-size: 1rem; font-weight: 700; cursor: pointer;
        }
        button[type=submit]:disabled { opacity: .6; }
        #locate-btn { padding: .65rem .9rem; border: 1px solid #cdd6cf; border-radius: .5rem; background: #fff; font-size: .875rem; cursor: pointer; }
        #flash { display: none; margin-top: 1rem; padding: .75rem 1rem; border-radius: .5rem; font-size: .9rem; }
        #flash.ok { display: block; background: #dcecdf; color: #16401f; }
        #flash.queued { display: block; background: #fdf1da; color: #6b4a12; }
        #flash.error { display: block; background: #fbdede; color: #6b1414; }
    </style>
</head>
<body>
    <x-offline-banner />

    <div class="wrap">
        <h1>Record checkpoint</h1>
        <p class="meta">Waybill {{ $shipment->waybill_number }}</p>

        <div id="flash" role="status" aria-live="polite"></div>

        <form id="checkpoint-form" autocomplete="off">
            @csrf
            <fieldset>
                <label>Status</label>
                <div class="status-options">
                    @foreach ($statuses as $status)
                        <label>
                            <input type="radio" name="status" value="{{ $status->value }}" required>
                            {{ $status->label() }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <label for="location">Location</label>
            <div class="locate-row">
                <input type="text" id="location" name="location" placeholder="e.g. Douala Port, Gate 3">
                <button type="button" id="locate-btn">Use GPS</button>
            </div>
            <input type="hidden" id="latitude" name="latitude">
            <input type="hidden" id="longitude" name="longitude">

            <label for="notes">Notes (optional)</label>
            <textarea id="notes" name="notes"></textarea>

            <button type="submit" id="submit-btn">Save checkpoint</button>
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

        const form = document.getElementById('checkpoint-form');
        const flash = document.getElementById('flash');
        const submitBtn = document.getElementById('submit-btn');
        const locateBtn = document.getElementById('locate-btn');
        const submitUrl = @json($submitUrl);
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        function showFlash(kind, message) {
            flash.className = kind;
            flash.textContent = message;
        }

        locateBtn.addEventListener('click', () => {
            if (!navigator.geolocation) return;
            locateBtn.disabled = true;
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    document.getElementById('latitude').value = pos.coords.latitude;
                    document.getElementById('longitude').value = pos.coords.longitude;
                    locateBtn.textContent = 'Location captured';
                },
                () => { locateBtn.disabled = false; },
                { enableHighAccuracy: true, timeout: 8000 },
            );
        });

        function formDataAsObject() {
            const fd = new FormData(form);
            const obj = {};
            for (const [key, value] of fd.entries()) {
                if (key === '_token' || value === '') continue;
                obj[key] = value;
            }
            // The client's own clock at fill-in time — this is what lets an
            // out-of-order sync (e.g. this "dispatched" checkpoint reaching
            // the server after a later "delivered" one already synced) be
            // recorded truthfully instead of misrepresented as "now". See
            // App\Services\CheckpointTracker::record().
            obj.occurred_at = new Date().toISOString();
            return obj;
        }

        // If we're still on this page when a previously-queued checkpoint
        // for this exact shipment finally syncs, tell the driver.
        OfflineQueue.onSync((evt) => {
            if (evt.url === submitUrl) {
                showFlash('ok', 'Synced — your queued checkpoint has now been saved.');
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
                        showFlash('ok', 'Checkpoint saved.');
                        form.reset();
                        submitBtn.disabled = false;
                        return;
                    }

                    if (resp.status >= 400 && resp.status < 500) {
                        // A real server-side rejection (validation, etc.) —
                        // not a connectivity problem, so surface it instead
                        // of silently queuing forever.
                        const body = await resp.json().catch(() => ({}));
                        showFlash('error', body.message || 'This checkpoint could not be saved. Please check the form and try again.');
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
            form.reset();
            submitBtn.disabled = false;
        });
    </script>
</body>
</html>
