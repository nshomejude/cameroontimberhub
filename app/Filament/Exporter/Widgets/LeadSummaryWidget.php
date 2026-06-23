<?php

namespace App\Filament\Exporter\Widgets;

use App\Enums\LeadStatus;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LeadSummaryWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $company = $user?->companies()->first();

        if (! $company) {
            return [];
        }

        $leads    = $company->leads();
        $newCount = (clone $leads)->where('status', LeadStatus::New->value)->count();
        $contacted = (clone $leads)->where('status', LeadStatus::Contacted->value)->count();
        $won      = (clone $leads)->where('status', LeadStatus::Won->value)->count();

        return [
            Stat::make('New leads', $newCount)
                ->description('Awaiting your response')
                ->color($newCount > 0 ? 'warning' : 'gray'),
            Stat::make('Contacted', $contacted)
                ->description('In progress')
                ->color('info'),
            Stat::make('Won', $won)
                ->description('Successful leads')
                ->color('success'),
        ];
    }
}
