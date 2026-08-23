<?php

namespace App\Providers\Filament\Concerns;

use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;

/**
 * The one place the admin and exporter panels agree on how they look.
 *
 * The owner asked for both Filament panels to read as the same product as the
 * hand-built buyer dashboard at `/account`. Rather than rebuilding the panels
 * as custom Blade, they are themed: this trait supplies the brand palette, the
 * logo, the self-hosted brand font and the shared Vite theme stylesheet
 * (`resources/css/filament/theme.css`), and both providers call it.
 *
 * Presentation only — no resource, table, form or action behaviour is touched.
 */
trait AppliesHubBranding
{
    protected function applyHubBranding(Panel $panel): Panel
    {
        return $panel
            ->viteTheme('resources/css/filament/theme.css')
            // The lockup has to work on two grounds: the forest logo column in
            // the panel and the white login card. One view renders both and
            // the theme picks which is visible — see brand-logo.blade.php.
            ->brandLogo(fn () => view('filament.brand-logo'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('brand/icon-96.png'))
            ->colors([
                // Forest is the platform's primary green (buttons, active
                // states); values are the `forest-*` ramp from app.css so a
                // primary button in a panel is the same green as one on the
                // marketing site or the buyer dashboard.
                'primary' => [
                    50 => '#edf7f0',
                    100 => '#d3ebda',
                    200 => '#a8d7b8',
                    300 => '#74bc8d',
                    400 => '#439c66',
                    500 => '#1f7d45',
                    600 => '#15703d',
                    700 => '#0a5223',
                    800 => '#073e20',
                    900 => '#052d1a',
                    950 => '#031c12',
                ],
                // Filament's gray drives page ground, borders and muted text.
                // The default (Zinc) is cool; the platform's neutrals are the
                // warm `sand-*` / `ink*` tokens, so gray is re-pitched warm.
                'gray' => [
                    50 => '#fbfaf8',
                    100 => '#f9f9f7',
                    200 => '#efefec',
                    300 => '#e0e0da',
                    400 => '#c2c2b8',
                    500 => '#8b8f8a',
                    600 => '#5b6560',
                    700 => '#454e49',
                    800 => '#2e3833',
                    900 => '#1f2823',
                    950 => '#17201a',
                ],
                // Status colours are kept aligned with `<x-account.status-pill>`:
                // success = forest, warning = timber, danger/info unchanged.
                'success' => [
                    50 => '#edf7f0',
                    100 => '#d3ebda',
                    200 => '#a8d7b8',
                    300 => '#74bc8d',
                    400 => '#439c66',
                    500 => '#1f7d45',
                    600 => '#15703d',
                    700 => '#0a5223',
                    800 => '#073e20',
                    900 => '#052d1a',
                    950 => '#031c12',
                ],
                'warning' => [
                    50 => '#faf6ef',
                    100 => '#f3e7d2',
                    200 => '#e6cca1',
                    300 => '#d7ac6c',
                    400 => '#c38851',
                    500 => '#a9713e',
                    600 => '#8a5a30',
                    700 => '#834b27',
                    800 => '#6c3f25',
                    900 => '#5a3622',
                    950 => '#3d2517',
                ],
            ])
            // The buyer dashboard is light-only — it has no dark palette and no
            // theme switcher — so a dark Filament would be the one surface of
            // the product that flips. Disabled rather than half-themed.
            ->darkMode(false)
            // Reuse the self-hosted Instrument Sans that Vite already builds for
            // the rest of the platform instead of Filament's Google-hosted
            // Inter. `@fonts` emits the built font stylesheet plus preloads.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => Blade::render('@fonts'),
            );
    }
}
