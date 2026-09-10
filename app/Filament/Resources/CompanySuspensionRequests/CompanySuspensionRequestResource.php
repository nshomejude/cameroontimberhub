<?php

namespace App\Filament\Resources\CompanySuspensionRequests;

use App\Filament\Resources\CompanySuspensionRequests\Pages\ListCompanySuspensionRequests;
use App\Filament\Resources\CompanySuspensionRequests\Tables\CompanySuspensionRequestsTable;
use App\Models\CompanySuspensionRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Second half of the two-person suspension control (blueprint §88-89). A
 * pending row here was created by one staff member (see CompaniesTable's
 * "suspend" action); a DIFFERENT staff member approves or rejects it here.
 * No create/edit/delete -- these rows are system-created and decided only
 * via the recordActions below. Mirrors VerificationRevocationRequestResource.
 */
class CompanySuspensionRequestResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.company_suspensions');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.compliance');
    }
    protected static ?string $model = CompanySuspensionRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPauseCircle;



    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('companies.suspend');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return CompanySuspensionRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanySuspensionRequests::route('/'),
        ];
    }
}
