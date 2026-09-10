<?php

namespace App\Filament\Resources\ComplianceCases;

use App\Filament\Resources\ComplianceCases\Pages\ListComplianceCases;
use App\Filament\Resources\ComplianceCases\Tables\ComplianceCasesTable;
use App\Models\ComplianceCase;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Minimal admin surface for ComplianceCase rows opened by OrderObserver (and
 * any other owner type in future). List + status update only — cases are
 * opened by the system, not authored by staff, so there is no create form.
 */
class ComplianceCaseResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.compliance_cases');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.compliance');
    }
    protected static ?string $model = ComplianceCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;


    protected static ?string $recordTitleAttribute = 'id';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('compliance.manage');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->can('compliance.manage');
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->can('compliance.manage');
    }

    public static function table(Table $table): Table
    {
        return ComplianceCasesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComplianceCases::route('/'),
        ];
    }
}
