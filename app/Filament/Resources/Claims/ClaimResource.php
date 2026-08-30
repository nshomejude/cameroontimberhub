<?php

namespace App\Filament\Resources\Claims;

use App\Filament\Resources\Claims\Pages\CreateClaim;
use App\Filament\Resources\Claims\Pages\EditClaim;
use App\Filament\Resources\Claims\Pages\ListClaims;
use App\Filament\Resources\Claims\Schemas\ClaimForm;
use App\Filament\Resources\Claims\Tables\ClaimsTable;
use App\Models\Claim;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ClaimResource extends Resource
{
    protected static ?string $model = Claim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Claims Register';

    protected static ?string $recordTitleAttribute = 'claim_text';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('pages.manage');
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->can('pages.manage');
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->can('pages.manage');
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->can('pages.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return ClaimForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClaimsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClaims::route('/'),
            'create' => CreateClaim::route('/create'),
            'edit' => EditClaim::route('/{record}/edit'),
        ];
    }
}
