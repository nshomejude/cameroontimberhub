<?php

namespace App\Filament\Resources\Rfqs\Tables;

use App\Enums\OrganisationType;
use App\Enums\RfqStatus;
use App\Models\Company;
use App\Models\Rfq;
use App\Services\LeadFlowService;
use App\Services\RfqMatchingService;
use App\Services\RfqTriageService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RfqsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_code')->label('Reference')->searchable()->sortable(),
                TextColumn::make('buyer_name')->description(fn (Rfq $r): ?string => $r->buyer_country_code)->searchable(),
                TextColumn::make('items')->label('Requested')->getStateUsing(fn (Rfq $r): string => $r->items->map(fn ($i) => $i->label())->join('; '))->wrap()->limit(80),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (RfqStatus $state): string => $state->label())
                    ->color(fn (RfqStatus $state): string => $state->color()),
                TextColumn::make('spam_score')->badge()
                    ->color(fn (int $state): string => $state >= 70 ? 'danger' : ($state >= 30 ? 'warning' : 'gray')),
                TextColumn::make('created_at')->date('d M Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options(collect(RfqStatus::cases())->mapWithKeys(fn (RfqStatus $s) => [$s->value => $s->label()])->all()),
                Filter::make('flagged')
                    ->label('Flagged / spam')
                    ->query(fn (Builder $q): Builder => $q->where(fn (Builder $x) => $x->where('spam_score', '>=', 30)->orWhere('is_spam', true)))
                    ->toggle(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ActionGroup::make([
                    Action::make('startReview')->label('Start review')->icon('heroicon-o-play-circle')->color('info')
                        ->visible(fn (Rfq $r): bool => $r->status === RfqStatus::New && static::canTriage())
                        ->action(fn (Rfq $record) => static::run(fn () => app(RfqTriageService::class)->startReview($record, auth()->user()), 'Review started')),

                    Action::make('approve')->label('Approve')->icon('heroicon-o-check-circle')->color('success')->requiresConfirmation()
                        ->visible(fn (Rfq $r): bool => in_array($r->status, [RfqStatus::New, RfqStatus::InReview], true) && static::canTriage())
                        ->action(fn (Rfq $record) => static::run(fn () => app(RfqTriageService::class)->approve($record, auth()->user()), 'RFQ approved — you can now route it')),

                    Action::make('aiSuggestions')->label('AI suggestions')->icon('heroicon-o-sparkles')->color('gray')
                        ->visible(fn (Rfq $r): bool => $r->status === RfqStatus::Approved && static::canRoute())
                        ->modalHeading('AI-suggested supplier shortlist')
                        ->modalContent(fn (Rfq $record) => view('filament.resources.rfqs.ai-suggestions', [
                            'suggestions' => app(RfqMatchingService::class)->suggestSuppliers($record),
                        ]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close'),

                    Action::make('route')->label('Route to companies')->icon('heroicon-o-paper-airplane')->color('success')
                        ->visible(fn (Rfq $r): bool => $r->status === RfqStatus::Approved && static::canRoute())
                        ->schema([
                            Select::make('companies')->label('Companies')->multiple()->required()->searchable()
                                ->options(fn (Rfq $record) => static::routingOptions($record))
                                ->helperText(fn (Rfq $record): ?string => $record->type?->targetOrganisationTypes() !== null
                                    ? "Suggested: companies suited to a {$record->type->label()} request are listed first."
                                    : null),
                        ])
                        ->action(function (Rfq $record, array $data): void {
                            $result = app(RfqTriageService::class)->routeDetailed($record, $data['companies'], auth()->user(), app(LeadFlowService::class));
                            $selected = count($data['companies']);

                            // Anything short of "every selected company got it"
                            // is a warning listing who was skipped and why.
                            $notification = Notification::make()->title("Routed to {$result->routed} of {$selected} company/companies");
                            if ($result->routed < $selected || $result->routed === 0) {
                                $notification->warning()->body('Not routed: '.$result->skippedSummary())->persistent();
                            } else {
                                $notification->success();
                            }
                            $notification->send();
                        }),

                    Action::make('reject')->label('Reject')->icon('heroicon-o-x-circle')->color('danger')
                        ->visible(fn (Rfq $r): bool => in_array($r->status, [RfqStatus::New, RfqStatus::InReview, RfqStatus::Spam], true) && static::canTriage())
                        ->schema([Textarea::make('reason')->required()->maxLength(500)])
                        ->action(fn (Rfq $record, array $data) => static::run(fn () => app(RfqTriageService::class)->reject($record, $data['reason'], auth()->user()), 'RFQ rejected')),

                    Action::make('markSpam')->label('Mark spam')->icon('heroicon-o-no-symbol')->color('danger')
                        ->visible(fn (Rfq $r): bool => in_array($r->status, [RfqStatus::New, RfqStatus::InReview], true) && static::canTriage())
                        ->action(fn (Rfq $record) => static::run(fn () => app(RfqTriageService::class)->markSpam($record, auth()->user()), 'Marked as spam')),

                    Action::make('close')->label('Close')->icon('heroicon-o-archive-box')->color('gray')->requiresConfirmation()
                        ->visible(fn (Rfq $r): bool => ! in_array($r->status, [RfqStatus::Closed], true) && static::canTriage())
                        ->action(fn (Rfq $record) => static::run(fn () => app(RfqTriageService::class)->close($record, auth()->user()), 'RFQ closed')),
                ])->label('Triage')->icon('heroicon-m-ellipsis-vertical'),
            ]);
    }

    /**
     * Verified companies for the manual routing selector. For RFQ types with
     * target organisation types (RfqType::targetOrganisationTypes()) the
     * suitable companies come first in a "Suggested" group; the rest stay
     * selectable under "Other companies" so staff can still override.
     *
     * @return array<int|string, mixed>
     */
    public static function routingOptions(Rfq $rfq): array
    {
        $label = fn (Company $company): string => $company->type instanceof OrganisationType
            ? "{$company->legal_name} ({$company->type->label()})"
            : $company->legal_name;

        $companies = Company::where('status', 'verified')->orderBy('legal_name')->get();
        $targetTypes = $rfq->type?->targetOrganisationTypes();

        if ($targetTypes === null) {
            return $companies->mapWithKeys(fn (Company $c) => [$c->id => $label($c)])->all();
        }

        [$suggested, $others] = $companies->partition(fn (Company $c) => in_array($c->type, $targetTypes, true));

        return array_filter([
            'Suggested for '.$rfq->type->label() => $suggested->mapWithKeys(fn (Company $c) => [$c->id => $label($c)])->all(),
            'Other companies' => $others->mapWithKeys(fn (Company $c) => [$c->id => $label($c)])->all(),
        ]);
    }

    protected static function run(callable $callback, string $message): void
    {
        $callback();
        Notification::make()->title($message)->success()->send();
    }

    protected static function canTriage(): bool
    {
        return (bool) auth()->user()?->can('rfqs.triage');
    }

    protected static function canRoute(): bool
    {
        return (bool) auth()->user()?->can('rfqs.route');
    }
}
