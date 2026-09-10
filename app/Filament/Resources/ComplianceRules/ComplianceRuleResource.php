<?php

namespace App\Filament\Resources\ComplianceRules;

use App\Filament\Resources\ComplianceRules\Pages\CreateComplianceRule;
use App\Filament\Resources\ComplianceRules\Pages\EditComplianceRule;
use App\Filament\Resources\ComplianceRules\Pages\ListComplianceRules;
use App\Filament\Resources\ComplianceRules\Schemas\ComplianceRuleForm;
use App\Filament\Resources\ComplianceRules\Tables\ComplianceRulesTable;
use App\Models\ComplianceRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ComplianceRuleResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.compliance_rules');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.compliance');
    }
    protected static ?string $model = ComplianceRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;


    protected static ?string $recordTitleAttribute = 'regulatory_framework';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('compliance.manage');
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->can('compliance.manage');
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->can('compliance.manage');
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->can('compliance.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return ComplianceRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ComplianceRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComplianceRules::route('/'),
            'create' => CreateComplianceRule::route('/create'),
            'edit' => EditComplianceRule::route('/{record}/edit'),
        ];
    }
}
