<?php

namespace App\Filament\Exporter\Resources\Leads;

use App\Filament\Exporter\Resources\Leads\Pages\EditLead;
use App\Filament\Exporter\Resources\Leads\Pages\ListLeads;
use App\Filament\Exporter\Resources\Leads\Schemas\LeadForm;
use App\Filament\Exporter\Resources\Leads\Tables\LeadsTable;
use App\Models\Lead;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeadResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.xnav.leads');
    }
    protected static ?string $model = Lead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;


    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->companies()->exists();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->companies()->exists();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()->when(
            $user,
            fn (Builder $query) => $query->whereHas('company', fn (Builder $c) => $c->dashboardOwned($user)),
            fn (Builder $query) => $query->whereRaw('1 = 0'),
        );
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return LeadForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeadsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeads::route('/'),
            'edit' => EditLead::route('/{record}/edit'),
        ];
    }
}
