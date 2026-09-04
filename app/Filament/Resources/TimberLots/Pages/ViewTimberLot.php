<?php

namespace App\Filament\Resources\TimberLots\Pages;

use App\Filament\Resources\TimberLots\TimberLotResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewTimberLot extends ViewRecord
{
    protected static string $resource = TimberLotResource::class;

    /**
     * Download link for the CTH Compliance Evidence Pack (blueprint §83).
     * Visible only to the lot's owning company or staff with
     * compliance.export -- CompliancePackController re-checks this on the
     * server side regardless, so this is purely a UI convenience.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadCompliancePack')
                ->label('Download Compliance Pack')
                ->icon('heroicon-o-document-arrow-down')
                ->url(fn () => route('compliance-pack.download', $this->record))
                ->openUrlInNewTab()
                ->visible(function () {
                    $user = auth()->user();

                    if (! $user) {
                        return false;
                    }

                    if ($user->can('compliance.export')) {
                        return true;
                    }

                    return $user->companies()->whereKey($this->record->company_id)->exists();
                }),
        ];
    }
}
