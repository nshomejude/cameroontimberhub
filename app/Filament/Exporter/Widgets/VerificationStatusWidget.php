<?php

namespace App\Filament\Exporter\Widgets;

use App\Models\Company;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class VerificationStatusWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $company = $user?->companies()->first();

        if (! $company) {
            return [];
        }

        $statusLabel = match ($company->status->value) {
            'verified'  => __('messages.filament.widgets.status_verified'),
            'pending'   => __('messages.filament.widgets.status_under_review'),
            'draft'     => __('messages.filament.widgets.status_draft'),
            'suspended' => __('messages.filament.widgets.status_suspended'),
            'rejected'  => __('messages.filament.widgets.status_rejected'),
            default     => ucfirst($company->status->value),
        };

        $statusColor = match ($company->status->value) {
            'verified'  => 'success',
            'pending'   => 'warning',
            'draft'     => 'gray',
            'suspended', 'rejected' => 'danger',
            default => 'gray',
        };

        $activeBadges = $company->activeBadges()->count();
        $completion   = $company->profile_completion ?? 0;

        return [
            Stat::make(__('messages.filament.widgets.company_status'), $statusLabel)->color($statusColor),
            Stat::make(__('messages.filament.widgets.active_badges'), $activeBadges)->color($activeBadges > 0 ? 'success' : 'gray'),
            Stat::make(__('messages.filament.widgets.profile_completion'), $completion . '%')
                ->color($completion >= 80 ? 'success' : ($completion >= 40 ? 'warning' : 'danger')),
        ];
    }
}
