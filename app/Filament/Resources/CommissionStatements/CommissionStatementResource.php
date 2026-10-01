<?php

namespace App\Filament\Resources\CommissionStatements;

use App\Enums\CommissionStatementStatus;
use App\Enums\RfqCurrency;
use App\Filament\Resources\CommissionStatements\Pages\ListCommissionStatements;
use App\Filament\Resources\CommissionStatements\Pages\ViewCommissionStatement;
use App\Filament\Resources\CommissionStatements\Schemas\CommissionStatementInfolist;
use App\Filament\Resources\CommissionStatements\Support\CommissionCollectionActions;
use App\Models\CommissionStatement;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * /admin → Commission statements: the monthly marketplace-commission
 * statements issued to suppliers (`commission:issue-statements`), collected
 * by manual MoMo / bank deposit (owner decision 2026-10-01).
 *
 * Read for `billing.view`; "Record deposit" (money received without a
 * supplier report) and "Void" need `payments.manage`. Issued amounts are
 * immutable (hash-chained) — there is no create or edit page.
 */
class CommissionStatementResource extends Resource
{
    protected static ?string $model = CommissionStatement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Commission statements';

    protected static ?string $modelLabel = 'commission statement';

    protected static ?string $recordTitleAttribute = 'statement_number';

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.platform');
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('billing.view');
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

    public static function infolist(Schema $schema): Schema
    {
        return CommissionStatementInfolist::configure($schema, admin: true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('company'))
            ->columns([
                TextColumn::make('statement_number')->label('Number')->searchable()->sortable(),
                TextColumn::make('company.name')->label('Company')->searchable(),
                TextColumn::make('period_start')->label('Period')->date('M Y')->sortable(),
                TextColumn::make('total_amount')->label('Total')
                    ->formatStateUsing(fn ($state, CommissionStatement $record) => $record->money($state))->sortable(),
                TextColumn::make('amount_paid')->label('Paid')
                    ->formatStateUsing(fn ($state, CommissionStatement $record) => $record->money($state)),
                TextColumn::make('amount_due')->label('Due')
                    ->state(fn (CommissionStatement $record) => $record->money($record->outstanding())),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (CommissionStatementStatus $state) => $state->label())
                    ->color(fn (CommissionStatementStatus $state) => $state->color()),
                TextColumn::make('due_date')->label('Due by')->date('d M Y')->sortable(),
            ])
            ->defaultSort('issued_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(collect(CommissionStatementStatus::cases())
                    ->mapWithKeys(fn (CommissionStatementStatus $s) => [$s->value => $s->label()])->all()),
                SelectFilter::make('currency')->options(collect(RfqCurrency::cases())
                    ->mapWithKeys(fn (RfqCurrency $c) => [$c->value => $c->value])->all()),
                Filter::make('overdue')->label('Past due & unpaid')
                    ->query(fn (Builder $query) => $query->open()->whereDate('due_date', '<', today())),
            ])
            ->recordActions([
                ViewAction::make(),
                CommissionCollectionActions::recordDeposit(),
                CommissionCollectionActions::voidStatement(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommissionStatements::route('/'),
            'view' => ViewCommissionStatement::route('/{record}'),
        ];
    }
}
