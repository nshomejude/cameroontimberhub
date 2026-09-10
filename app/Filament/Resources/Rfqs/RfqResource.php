<?php

namespace App\Filament\Resources\Rfqs;

use App\Filament\Resources\Rfqs\Pages\ListRfqs;
use App\Filament\Resources\Rfqs\Tables\RfqsTable;
use App\Models\Rfq;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RfqResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.rfq_queue');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.leads');
    }

    public static function getModelLabel(): string
    {
        return __('messages.filament.model.rfq_one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('messages.filament.model.rfq_many');
    }
    protected static ?string $model = Rfq::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;





    protected static ?string $recordTitleAttribute = 'reference_code';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('rfqs.triage');
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

    /** Only email-verified RFQs are actionable (the verification gate). */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('email_verified_at');
    }

    public static function table(Table $table): Table
    {
        return RfqsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRfqs::route('/'),
        ];
    }
}
