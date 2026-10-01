<?php

namespace App\Filament\Exporter\Resources\TransformationRequests;

use App\Filament\Exporter\Resources\TransformationRequests\Pages\ListTransformationRequests;
use App\Filament\Exporter\Resources\TransformationRequests\Pages\ViewTransformationRequest;
use App\Filament\Exporter\Resources\TransformationRequests\Schemas\TransformationRequestInfolist;
use App\Filament\Exporter\Resources\TransformationRequests\Tables\TransformationRequestsTable;
use App\Models\TransformationRequest;
use App\Services\TransformationRequestService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Company-side Transformation Requests: the provider's inbox (received) and
 * the requester's outbox (sent). Read-only records; every state change goes
 * through TransformationRequestService via the actions in
 * TransformationRequestActions, which are gated by the service's own
 * actionsFor() so the web and the mobile API offer the same moves.
 */
class TransformationRequestResource extends Resource
{
    protected static ?string $model = TransformationRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'reference_code';

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.transformation_requests');
    }

    /**
     * Provider-type companies (Processor/Manufacturer/Artisan) always see it;
     * any other company sees it once it has sent a request.
     */
    public static function canViewAny(): bool
    {
        $user = auth()->user();
        $service = app(TransformationRequestService::class);
        $company = $user ? $service->company($user) : null;

        if ($company === null) {
            return false;
        }

        return (bool) $company->type?->isTransformationProvider()
            || $service->sent($user)->exists()
            || $service->received($user)->exists();
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
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

    /** Only requests where the caller's company is the requester or the provider. */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $companyId = $user ? app(TransformationRequestService::class)->company($user)?->getKey() : null;

        if ($companyId === null) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()->where(fn (Builder $q) => $q
            ->where('requester_company_id', $companyId)
            ->orWhere('provider_company_id', $companyId));
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function infolist(Schema $schema): Schema
    {
        return TransformationRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TransformationRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransformationRequests::route('/'),
            'view' => ViewTransformationRequest::route('/{record}'),
        ];
    }
}
