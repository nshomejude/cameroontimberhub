<?php

namespace App\Filament\Exporter\Resources\InspectionRequests;

use App\Filament\Exporter\Resources\InspectionRequests\Pages\CreateInspectionRequest;
use App\Filament\Exporter\Resources\InspectionRequests\Pages\ListInspectionRequests;
use App\Filament\Exporter\Resources\InspectionRequests\Schemas\InspectionRequestForm;
use App\Filament\Exporter\Resources\InspectionRequests\Tables\InspectionRequestsTable;
use App\Models\Inspection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Self-service "Request Inspection" for companies (blueprint §27 gap): a
 * company picks one of its own TimberLots, an inspection_type, and a
 * preferred date. Built on the existing App\Models\Inspection rather than a
 * new model -- creating an Inspection *is* requesting one; inspector_id is
 * populated automatically by matching (see CreateInspectionRequest) or left
 * null for staff to assign, mirroring how CapacityResource builds on the
 * existing Capacity model instead of inventing a parallel one.
 *
 * Deliberately list+create only: once submitted, the inspection lifecycle
 * (scheduling changes, report authoring, finalisation) belongs to the
 * inspector/staff, not the requesting company -- see the read-only staff
 * App\Filament\Resources\Inspections\InspectionResource for the same
 * philosophy applied there.
 */
class InspectionRequestResource extends Resource
{
    protected static ?string $model = Inspection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Inspection Requests';

    protected static ?string $modelLabel = 'inspection request';

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->companies()->exists();
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->companies()->exists();
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /**
     * Scoped to inspections whose timber lot belongs to the current user's
     * company -- a company can never see (or, via getRecordRouteBindingEloquentQuery,
     * route to) another company's inspection requests.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $company = $user?->companies()->first();

        if (! $company) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->whereHas('timberLot', function (Builder $query) use ($company): void {
                $query->where('company_id', $company->getKey());
            });
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return InspectionRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InspectionRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInspectionRequests::route('/'),
            'create' => CreateInspectionRequest::route('/create'),
        ];
    }
}
