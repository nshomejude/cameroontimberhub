<?php

namespace App\Filament\Resources\VerificationRevocationRequests;

use App\Filament\Resources\VerificationRevocationRequests\Pages\ListVerificationRevocationRequests;
use App\Filament\Resources\VerificationRevocationRequests\Tables\VerificationRevocationRequestsTable;
use App\Models\VerificationRevocationRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Second half of the two-person revocation control (blueprint §89). A
 * pending row here was created by one staff member (see
 * VerificationBadgeResource's "Request revocation" action); a DIFFERENT
 * staff member approves or rejects it here. No create/edit/delete -- these
 * rows are system-created and decided only via the recordActions below.
 */
class VerificationRevocationRequestResource extends Resource
{
    protected static ?string $model = VerificationRevocationRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|\UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Verification revocations';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('verification.review');
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
        return VerificationRevocationRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVerificationRevocationRequests::route('/'),
        ];
    }
}
