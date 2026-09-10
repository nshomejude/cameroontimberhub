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
            Stat::make(__('messages.filament.widgets.new_leads'), $newCount)
                ->description(__('messages.filament.widgets.new_leads_desc'))
                ->color($newCount > 0 ? 'warning' : 'gray'),
            Stat::make(__('messages.filament.widgets.contacted'), $contacted)
                ->description(__('messages.filament.widgets.contacted_desc'))
                ->color('info'),
            Stat::make(__('messages.filament.widgets.won'), $won)
                ->description(__('messages.filament.widgets.won_desc'))
                ->color('success'),
        ];
    }
}
