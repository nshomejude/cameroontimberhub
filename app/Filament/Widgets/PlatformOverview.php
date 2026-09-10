<?php

namespace App\Filament\Widgets;

use App\Models\Company;
use App\Models\Rfq;
use App\Models\VerificationRequest;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformOverview extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('companies.view');
    }

    protected function getStats(): array
    {
        return [
            Stat::make(__('messages.filament.widgets.verified_companies'), Company::where('status', 'verified')->count())
                ->description(__('messages.filament.widgets.verified_companies_desc'))
                ->color('success'),
            Stat::make(__('messages.filament.widgets.pending_companies'), Company::where('status', 'pending')->count())
                ->description(__('messages.filament.widgets.pending_companies_desc'))
                ->color('warning'),
            Stat::make(__('messages.filament.widgets.open_verifications'), VerificationRequest::open()->count())
                ->description(__('messages.filament.widgets.open_verifications_desc')),
            Stat::make(__('messages.filament.widgets.rfqs_to_triage'), Rfq::verified()->where('status', 'new')->count())
                ->description(__('messages.filament.widgets.rfqs_to_triage_desc'))
                ->color('warning'),
        ];
    }
}
