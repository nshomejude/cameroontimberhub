<?php

namespace App\Filament\Resources\CommissionDeposits;

use App\Enums\CommissionDepositMethod;
use App\Enums\CommissionDepositStatus;
use App\Filament\Resources\CommissionDeposits\Pages\ListCommissionDeposits;
use App\Filament\Resources\CommissionStatements\CommissionStatementResource;
use App\Filament\Resources\CommissionStatements\Support\CommissionCollectionActions;
use App\Models\CommissionDeposit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * /admin → Commission deposits: the verification queue for manual commission
 * payments (MTN MoMo / Orange Money / bank) reported by suppliers against
 * their statements (owner decision 2026-10-01). Finance checks the money
 * arrived, then Confirms (records the amount actually received → statement
 * partially paid / paid) or Rejects with a reason the supplier sees.
 *
 * Listing + proof download need `billing.view`; Confirm / Reject need
 * `payments.manage` (CommissionCollectionActions / CommissionCollectionService).
 * Defaults to the pending queue.
 */
class CommissionDepositResource extends Resource
{
    protected static ?string $model = CommissionDeposit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Commission deposits';

    protected static ?string $modelLabel = 'commission deposit';

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.platform');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = CommissionDeposit::query()->where('status', CommissionDepositStatus::Pending->value)->count();

        return $pending > 0 ? (string) $pending : null;
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

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['company', 'statement', 'reportedBy', 'reviewedBy']))
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('company.name')->label('Company')->searchable(),
                TextColumn::make('statement.statement_number')->label('Statement')
                    ->url(fn (CommissionDeposit $record) => $record->statement
                        ? CommissionStatementResource::getUrl('view', ['record' => $record->statement])
                        : null),
                TextColumn::make('method')->formatStateUsing(fn (CommissionDepositMethod $state) => $state->label()),
                TextColumn::make('transaction_reference')->label('Reference')->searchable()->copyable(),
                TextColumn::make('amount')->label('Reported')
                    ->formatStateUsing(fn ($state, CommissionDeposit $record) => $record->money($state)),
                TextColumn::make('amount_received')->label('Received')
                    ->formatStateUsing(fn ($state, CommissionDeposit $record) => $record->money($state))->placeholder('—'),
                TextColumn::make('paid_on')->label('Paid on')->date('d M Y')->sortable(),
                TextColumn::make('source')->badge()->formatStateUsing(fn (string $state) => $state === 'admin' ? 'Recorded by finance' : 'Supplier report'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (CommissionDepositStatus $state) => $state->label())
                    ->color(fn (CommissionDepositStatus $state) => $state->color())
                    ->tooltip(fn (CommissionDeposit $record) => $record->rejection_reason),
                TextColumn::make('reviewedBy.name')->label('Reviewed by')->placeholder('—'),
                TextColumn::make('created_at')->label('Reported')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(CommissionDepositStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all())
                    ->default(CommissionDepositStatus::Pending->value),
                SelectFilter::make('method')->options(CommissionDepositMethod::options()),
            ])
            ->recordActions([
                CommissionCollectionActions::downloadProof(),
                CommissionCollectionActions::confirmDeposit(),
                CommissionCollectionActions::rejectDeposit(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommissionDeposits::route('/'),
        ];
    }
}
