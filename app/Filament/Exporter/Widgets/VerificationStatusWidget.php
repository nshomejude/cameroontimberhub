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
            'verified'  => 'Verified',
            'pending'   => 'Under review',
            'draft'     => 'Draft — not submitted',
            'suspended' => 'Suspended',
            'rejected'  => 'Rejected',
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
            Stat::make('Company status', $statusLabel)->color($statusColor),
            Stat::make('Active badges', $activeBadges)->color($activeBadges > 0 ? 'success' : 'gray'),
            Stat::make('Profile completion', $completion . '%')
                ->color($completion >= 80 ? 'success' : ($completion >= 40 ? 'warning' : 'danger')),
        ];
    }
}
