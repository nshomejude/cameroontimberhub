<?php

namespace App\Filament\Resources\Companies\Tables;

use App\Actions\Company\RequestCompanySuspension;
use App\Enums\CompanyStatus;
use App\Enums\SupplierType;
use App\Models\Company;
use App\Models\CompanySuspensionRequest;
use App\Models\Plan;
use App\Services\CompanyStatusService;
use App\Services\SubscriptionService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('legal_name')
                    ->label('Company')
                    ->description(fn (Company $record): ?string => $record->trade_name)
                    ->searchable(['legal_name', 'trade_name'])
                    ->sortable(),
                TextColumn::make('region')->searchable()->sortable()->toggleable(),
                TextColumn::make('supplier_type')
                    ->label('Type')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (SupplierType $state): string => $state->label())
                    ->color(fn (SupplierType $state): string => $state->color())
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('response_rate_percent')
                    ->label('Response')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : $state.'%')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('years_experience')
                    ->label('Experience')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : $state.' yrs')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CompanyStatus $state): string => $state->label())
                    ->color(fn (CompanyStatus $state): string => $state->color()),
                IconColumn::make('has_active_badge')
                    ->label('Badge')
                    ->boolean()
                    ->state(fn (Company $record): bool => $record->activeBadges()->exists()),
                TextColumn::make('species_count')->counts('species')->label('Species')->badge()->sortable(),
                IconColumn::make('is_featured')->label('Featured')->boolean()->toggleable(),
                TextColumn::make('featured_entitlement_warning')
                    ->label('')
                    ->badge()
                    ->color('danger')
                    ->placeholder('')
                    ->state(fn (Company $record): ?string => $record->is_featured && ! $record->hasFeature('featured') ? 'Featured without plan' : null)
                    ->toggleable(),
                TextColumn::make('created_at')->date('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options(collect(CompanyStatus::cases())->mapWithKeys(fn (CompanyStatus $s) => [$s->value => $s->label()])->all()),
                SelectFilter::make('supplier_type')
                    ->label('Supplier type')
                    ->multiple()
                    ->options(SupplierType::options()),
                TernaryFilter::make('is_featured')->label('Featured'),
                TrashedFilter::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
                ActionGroup::make(static::statusActions())->label('Manage')->icon('heroicon-m-ellipsis-vertical'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    BulkAction::make('assignPlan')->label('Assign plan')
                        ->icon('heroicon-o-credit-card')->color('info')
                        ->visible(fn (): bool => (bool) auth()->user()?->can('plans.manage'))
                        ->schema([
                            Select::make('plan_id')->label('Plan')->required()
                                ->options(fn () => Plan::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data): void {
                            $plan = Plan::findOrFail($data['plan_id']);
                            $service = app(SubscriptionService::class);

                            foreach ($records as $record) {
                                $service->assign($record, $plan, auth()->user());
                            }

                            Notification::make()->title('Plan assigned to '.$records->count().' compan'.($records->count() === 1 ? 'y' : 'ies'))->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /** @return array<Action> */
    protected static function statusActions(): array
    {
        $service = fn (): CompanyStatusService => app(CompanyStatusService::class);
        $canManage = fn (): bool => (bool) auth()->user()?->can('companies.manage');
        $canSuspend = fn (): bool => (bool) auth()->user()?->can('companies.suspend');
        $notify = fn (string $msg) => Notification::make()->title($msg)->success()->send();
        $reason = fn () => [Textarea::make('reason')->required()->maxLength(500)];

        return [
            Action::make('approve')
                ->icon('heroicon-o-check-circle')->color('success')->requiresConfirmation()
                ->visible(fn (Company $r): bool => $r->status === CompanyStatus::Pending && $canManage())
                ->action(function (Company $record) use ($service, $notify): void {
                    $service()->approve($record, auth()->user());
                    $notify('Company approved');
                }),

            Action::make('reject')
                ->icon('heroicon-o-x-circle')->color('danger')->schema($reason())
                ->visible(fn (Company $r): bool => $r->status === CompanyStatus::Pending && $canManage())
                ->action(function (Company $record, array $data) use ($service, $notify): void {
                    $service()->reject($record, $data['reason'], auth()->user());
                    $notify('Company rejected');
                }),

            Action::make('returnToDraft')->label('Return to draft')
                ->icon('heroicon-o-arrow-uturn-left')->color('gray')->schema($reason())
                ->visible(fn (Company $r): bool => $r->status === CompanyStatus::Pending && $canManage())
                ->action(function (Company $record, array $data) use ($service, $notify): void {
                    $service()->returnToDraft($record, $data['reason'], auth()->user());
                    $notify('Returned to draft');
                }),

            Action::make('suspend')
                ->label('Request suspension')
                ->icon('heroicon-o-pause-circle')->color('warning')->schema($reason())
                ->modalDescription('This creates a pending suspension request. A different staff member must approve it before the company is actually suspended.')
                ->visible(fn (Company $r): bool => $r->status === CompanyStatus::Verified && $canSuspend()
                    && ! CompanySuspensionRequest::where('company_id', $r->getKey())->where('status', 'pending')->exists())
                ->action(function (Company $record, array $data) use ($notify): void {
                    app(RequestCompanySuspension::class)->execute($record, $data['reason'], auth()->user());
                    $notify('Suspension request created — awaiting a different staff member\'s approval');
                }),

            Action::make('reinstate')
                ->icon('heroicon-o-play-circle')->color('success')->schema($reason())
                ->visible(fn (Company $r): bool => $r->status === CompanyStatus::Suspended && $canSuspend())
                ->action(function (Company $record, array $data) use ($service, $notify): void {
                    $service()->reinstate($record, $data['reason'], auth()->user());
                    $notify('Company reinstated');
                }),

            Action::make('archive')
                ->icon('heroicon-o-archive-box')->color('gray')->requiresConfirmation()
                ->visible(fn (Company $r): bool => $r->status !== CompanyStatus::Archived && $canManage())
                ->action(function (Company $record) use ($service, $notify): void {
                    $service()->archive($record, auth()->user());
                    $notify('Company archived');
                }),

            Action::make('toggleFeatured')
                ->label(fn (Company $r): string => $r->is_featured ? 'Unfeature' : 'Feature')
                ->icon('heroicon-o-star')->color('warning')
                ->visible(fn (): bool => $canManage())
                ->action(function (Company $record) use ($notify): void {
                    // Featuring is gated by the company's plan entitlement.
                    if (! $record->is_featured && ! $record->hasFeature('featured')) {
                        Notification::make()->title("This company's plan does not include featured placement.")->warning()->send();

                        return;
                    }
                    $record->update(['is_featured' => ! $record->is_featured]);
                    $notify($record->is_featured ? 'Company featured' : 'Company unfeatured');
                }),

            Action::make('assignPlan')->label('Assign plan')
                ->icon('heroicon-o-credit-card')->color('info')
                ->visible(fn (): bool => (bool) auth()->user()?->can('plans.manage'))
                ->schema([
                    Select::make('plan_id')->label('Plan')->required()
                        ->options(fn () => Plan::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')),
                ])
                ->action(function (Company $record, array $data) use ($notify): void {
                    app(SubscriptionService::class)->assign($record, Plan::findOrFail($data['plan_id']), auth()->user());
                    $notify('Plan assigned');
                }),
        ];
    }
}
