<?php

namespace App\Filament\Resources\TaxRules;

use App\Filament\Resources\TaxRules\Pages\CreateTaxRule;
use App\Filament\Resources\TaxRules\Pages\EditTaxRule;
use App\Filament\Resources\TaxRules\Pages\ListTaxRules;
use App\Filament\Resources\TaxRules\Schemas\TaxRuleForm;
use App\Filament\Resources\TaxRules\Tables\TaxRulesTable;
use App\Models\TaxRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * /admin → Tax Rules (billing engine M5, plan §7.3 / §124).
 *
 * The single admin surface for the configurable tax engine — jurisdiction,
 * rate, effective window, active toggle. No rate is hard-coded anywhere;
 * a row here is the only source of truth. Gated by `pricing.manage`
 * (super_admin + finance_officer). Editing an active rule's rate is blocked
 * at the model layer — create a new rule with a later effective_from.
 */
class TaxRuleResource extends Resource
{
    protected static ?string $model = TaxRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.tax_rules');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.platform');
    }

    public static function getModelLabel(): string
    {
        return __('messages.filament.model.tax_rule_one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('messages.filament.model.tax_rule_many');
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('pricing.manage');
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->can('pricing.manage');
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->can('pricing.manage');
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->can('pricing.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return TaxRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaxRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxRules::route('/'),
            'create' => CreateTaxRule::route('/create'),
            'edit' => EditTaxRule::route('/{record}/edit'),
        ];
    }
}
