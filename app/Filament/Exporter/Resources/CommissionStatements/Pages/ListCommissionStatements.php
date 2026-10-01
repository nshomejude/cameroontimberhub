<?php

namespace App\Filament\Exporter\Resources\CommissionStatements\Pages;

use App\Filament\Exporter\Resources\CommissionStatements\CommissionStatementResource;
use App\Services\Commission\CommissionCollectionService;
use Filament\Resources\Pages\ListRecords;

class ListCommissionStatements extends ListRecords
{
    protected static string $resource = CommissionStatementResource::class;

    /** Outstanding balance per currency, e.g. "Outstanding: XAF 45,000 (overdue)". */
    public function getSubheading(): ?string
    {
        $company = auth()->user()?->companies()->first();

        if ($company === null) {
            return null;
        }

        $summary = app(CommissionCollectionService::class)->summary($company);

        if ($summary['balances'] === []) {
            return 'Nothing outstanding. Marketplace commission is billed monthly and paid by Mobile Money or bank deposit.';
        }

        $parts = collect($summary['balances'])
            ->map(fn (array $b) => $b['outstanding_formatted'].($b['overdue'] ? ' (overdue)' : ''))
            ->implode(' · ');

        return 'Outstanding: '.$parts
            .($summary['quoting_blocked'] ? ' — new quotes are blocked until overdue commission is paid.' : '');
    }
}
