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
            Stat::make('Verified companies', Company::where('status', 'verified')->count())
                ->description('Live in the directory')
                ->color('success'),
            Stat::make('Pending companies', Company::where('status', 'pending')->count())
                ->description('Awaiting review')
                ->color('warning'),
            Stat::make('Open verifications', VerificationRequest::open()->count())
                ->description('In the verification queue'),
            Stat::make('RFQs to triage', Rfq::verified()->where('status', 'new')->count())
                ->description('New verified requests')
                ->color('warning'),
        ];
    }
}
