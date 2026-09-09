import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // Shared Filament panel theme — registered on both the admin
                // and exporter panels via `->viteTheme()`.
                'resources/css/filament/theme.css',
                // Offline-write-queue module (blueprint §45-46), imported
                // directly via Vite::asset() from standalone Blade pages
                // (resources/js/offline-queue.js) — e.g.
                // resources/views/public/inspector/report.blade.php and
                // resources/views/public/logistics/checkpoint.blade.php.
                // Must be a build input in its own right for those
                // Vite::asset() lookups to resolve.
                'resources/js/offline-queue.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
