<?php

namespace App\Providers\Filament;

use App\Http\Middleware\EnsureExporterOnboarded;
use App\Http\Middleware\RedirectIncompleteOnboarding;
use App\Providers\Filament\Concerns\AppliesHubBranding;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class ExporterPanelProvider extends PanelProvider
{
    use AppliesHubBranding;

    public function panel(Panel $panel): Panel
    {
        return $this->applyHubBranding($panel)
            ->id('exporter')
            ->path('dashboard')
            ->authGuard('web')
            ->brandName('Cameroon Timber Hub')
            ->login()
            ->discoverResources(in: app_path('Filament/Exporter/Resources'), for: 'App\Filament\Exporter\Resources')
            ->discoverPages(in: app_path('Filament/Exporter/Pages'), for: 'App\Filament\Exporter\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Exporter/Widgets'), for: 'App\Filament\Exporter\Widgets')
            // FilamentInfoWidget (the vendor "filament v5.x / Docs / GitHub"
            // promo card) is deliberately not registered: it is the one panel
            // surface that advertises the framework rather than the product.
            ->widgets([
                AccountWidget::class,
            ])
            // Fleet & driver registry (/fleet) is a plain Blade route, not a
            // Filament resource, but its audience is exactly this panel's
            // users (company members). Surface it here — it is otherwise
            // unreachable from any navigation.
            ->navigationItems([
                NavigationItem::make('Fleet Registry')
                    ->url(fn (): string => route('fleet.index'))
                    ->icon('heroicon-o-truck')
                    ->sort(45)
                    ->isActiveWhen(fn (): bool => request()->routeIs('fleet.index')),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureExporterOnboarded::class,
                RedirectIncompleteOnboarding::class,
            ]);
    }
}
