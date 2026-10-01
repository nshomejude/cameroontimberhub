<?php

namespace App\Filament\Resources\Companies\Tables;

use App\Actions\Agent\ModerateAgentSubmission;
use App\Actions\Company\RequestCompanySuspension;
use App\Domain\Commerce\Commands\AssignSubscriptionCommand;
use App\Enums\CompanyStatus;
use App\Enums\SupplierType;
use App\Models\Company;
use App\Models\CompanySuspensionRequest;
use App\Models\Plan;
use App\Services\CompanyStatusService;
use App\Support\Bus\CommandBus;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
                TextColumn::make('source')->label('Source')->badge()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('needs_review')->label('Needs review')->boolean()->toggleable(isToggledHiddenByDefault: true),
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
                // Agent Ingestion Gateway moderation queue (docs/api/AGENT_INGESTION.md).
                TernaryFilter::make('agent_submissions')->label('Agent submissions')
                    ->placeholder('All companies')
                    ->trueLabel('Agent-sourced, awaiting review')
                    ->falseLabel('Agent-sourced, reviewed')
                    ->queries(
                        true: fn ($q) => $q->where('source', 'like', 'agent:%')->where('needs_review', true),
                        false: fn ($q) => $q->where('source', 'like', 'agent:%')->where('needs_review', false),
                        blank: fn ($q) => $q,
                    ),
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
                            $bus = app(CommandBus::class);
                            $actor = auth()->user();

                            foreach ($records as $record) {
                                $bus->dispatch(new AssignSubscriptionCommand(
                                    companyId: $record->getKey(),
                                    planId: $plan->getKey(),
                                    actingUserId: $actor?->getKey(),
                                ));
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
            ...static::agentModerationActions(),

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
                    app(CommandBus::class)->dispatch(new AssignSubscriptionCommand(
                        companyId: $record->getKey(),
                        planId: Plan::findOrFail($data['plan_id'])->getKey(),
                        actingUserId: auth()->user()?->getKey(),
                    ));
                    $notify('Plan assigned');
                }),
        ];
    }

    /**
     * Agent Ingestion Gateway moderation (see ModerateAgentSubmission). Only
     * shown on agent-sourced companies.
     *
     * @return array<Action>
     */
    protected static function agentModerationActions(): array
    {
        $moderate = fn (): ModerateAgentSubmission => app(ModerateAgentSubmission::class);
        // Dedicated moderation permission (verification officers, moderators)
        // OR full company authority.
        $canManage = fn (): bool => (bool) (auth()->user()?->can('agent-submissions.moderate')
            || auth()->user()?->can('companies.manage'));
        $isAgent = fn (Company $r): bool => str_starts_with((string) $r->source, 'agent:');

        return [
            Action::make('agentApprove')->label('Approve listing')
                ->icon('heroicon-o-check-badge')->color('success')->requiresConfirmation()
                ->modalDescription('Clears the review flag and sends the company into the normal verification queue. It stays hidden until verified.')
                ->visible(fn (Company $r): bool => $isAgent($r) && $r->needs_review && $canManage())
                ->action(function (Company $record) use ($moderate): void {
                    $moderate()->approve($record, auth()->user());
                    Notification::make()->title('Agent submission approved — now pending verification')->success()->send();
                }),

            Action::make('agentPublishProducts')->label('Publish products')
                ->icon('heroicon-o-rocket-launch')->color('info')->requiresConfirmation()
                ->visible(fn (Company $r): bool => $isAgent($r) && $canManage())
                ->action(function (Company $record) use ($moderate): void {
                    if ($record->status !== CompanyStatus::Verified) {
                        Notification::make()->title('Verify the company before publishing its products.')->warning()->send();

                        return;
                    }
                    $count = $moderate()->publishProducts($record, auth()->user());
                    Notification::make()->title("Published {$count} product(s)")->success()->send();
                }),

            Action::make('agentReject')->label('Reject submission')
                ->icon('heroicon-o-no-symbol')->color('danger')
                ->schema([Textarea::make('reason')->maxLength(500)])
                ->visible(fn (Company $r): bool => $isAgent($r) && $r->status !== CompanyStatus::Archived && $canManage())
                ->action(function (Company $record, array $data) use ($moderate): void {
                    $moderate()->reject($record, auth()->user(), $data['reason'] ?? null);
                    Notification::make()->title('Agent submission rejected and archived')->success()->send();
                }),

            Action::make('agentAttachOwner')->label('Attach owner (claim)')
                ->icon('heroicon-o-user-plus')->color('gray')
                ->schema([TextInput::make('email')->email()->required()])
                ->visible(fn (Company $r): bool => $isAgent($r) && $canManage())
                ->action(function (Company $record, array $data) use ($moderate): void {
                    $moderate()->attachOwner($record, $data['email'], auth()->user());
                    Notification::make()->title('Owner attached — agents can no longer modify this company')->success()->send();
                }),
        ];
    }
}
