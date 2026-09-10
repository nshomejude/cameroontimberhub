<?php

namespace App\Filament\Resources\VerificationRequests;

use App\Filament\Resources\VerificationRequests\Pages\ListVerificationRequests;
use App\Filament\Resources\VerificationRequests\Tables\VerificationRequestsTable;
use App\Models\VerificationRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class VerificationRequestResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.verification_queue');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.compliance');
    }
    protected static ?string $model = VerificationRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;



    protected static ?int $navigationSort = 1;

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
        return VerificationRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVerificationRequests::route('/'),
        ];
    }
}
