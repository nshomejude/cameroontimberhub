<?php

namespace App\Filament\Resources\CommissionRules;

use App\Filament\Resources\CommissionRules\Pages\CreateCommissionRule;
use App\Filament\Resources\CommissionRules\Pages\EditCommissionRule;
use App\Filament\Resources\CommissionRules\Pages\ListCommissionRules;
use App\Filament\Resources\CommissionRules\Schemas\CommissionRuleForm;
use App\Filament\Resources\CommissionRules\Tables\CommissionRulesTable;
use App\Models\CommissionRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * /admin → Commission Rules (billing engine M7, plan §15 / §124).
 *
 * The single admin surface for the marketplace-commission engine —
 * segment/plan-tier scoping, domestic/international rate, caps, effective
 * window, active toggle. No rate is hard-coded anywhere; a row here is the
 * only source of truth. Gated by `pricing.manage` (super_admin +
 * finance_officer). Editing an active rule's rate/cap is blocked at the
 * model layer — create a new rule with a later effective_from.
 */
class CommissionRuleResource extends Resource
{
    protected static ?string $model = CommissionRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.commission_rules');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.platform');
    }

    public static function getModelLabel(): string
    {
        return __('messages.filament.model.commission_rule_one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('messages.filament.model.commission_rule_many');
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
        return CommissionRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CommissionRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommissionRules::route('/'),
            'create' => CreateCommissionRule::route('/create'),
            'edit' => EditCommissionRule::route('/{record}/edit'),
        ];
    }
}
