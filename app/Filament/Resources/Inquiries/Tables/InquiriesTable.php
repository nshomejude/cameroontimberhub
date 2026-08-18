<?php

namespace App\Filament\Resources\Inquiries\Tables;

use App\Enums\RfqStatus;
use App\Models\CompanyInquiry;
use App\Services\InquiryTriageService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InquiriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.legal_name')->label('Company')->searchable(),
                TextColumn::make('name')->label('Buyer')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('message')->wrap()->limit(80),
                IconColumn::make('email_verified_at')->label('Verified')->boolean(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (RfqStatus $state): string => $state->label())
                    ->color(fn (RfqStatus $state): string => $state->color()),
                TextColumn::make('created_at')->label('Submitted')->date('d M Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options(collect(RfqStatus::cases())->mapWithKeys(fn (RfqStatus $s) => [$s->value => $s->label()])->all()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ActionGroup::make([
                    Action::make('startReview')->label('Start review')->icon('heroicon-o-play-circle')->color('info')
                        ->visible(fn (CompanyInquiry $r): bool => $r->status === RfqStatus::New && static::canReview())
                        ->action(fn (CompanyInquiry $record) => static::run(fn () => app(InquiryTriageService::class)->startReview($record, auth()->user()), 'Review started')),

                    Action::make('approve')->label('Approve')->icon('heroicon-o-check-circle')->color('success')->requiresConfirmation()
                        ->visible(fn (CompanyInquiry $r): bool => in_array($r->status, [RfqStatus::New, RfqStatus::InReview], true) && static::canReview())
                        ->action(fn (CompanyInquiry $record) => static::run(fn () => app(InquiryTriageService::class)->approve($record, auth()->user()), 'Inquiry approved')),

                    Action::make('reject')->label('Reject')->icon('heroicon-o-x-circle')->color('danger')
                        ->visible(fn (CompanyInquiry $r): bool => in_array($r->status, [RfqStatus::New, RfqStatus::InReview, RfqStatus::Spam], true) && static::canReview())
                        ->schema([Textarea::make('reason')->required()->maxLength(500)])
                        ->action(fn (CompanyInquiry $record, array $data) => static::run(fn () => app(InquiryTriageService::class)->reject($record, $data['reason'], auth()->user()), 'Inquiry rejected')),

                    Action::make('markSpam')->label('Mark spam')->icon('heroicon-o-no-symbol')->color('danger')
                        ->visible(fn (CompanyInquiry $r): bool => in_array($r->status, [RfqStatus::New, RfqStatus::InReview], true) && static::canReview())
                        ->action(fn (CompanyInquiry $record) => static::run(fn () => app(InquiryTriageService::class)->markSpam($record, auth()->user()), 'Marked as spam')),

                    Action::make('close')->label('Close')->icon('heroicon-o-archive-box')->color('gray')->requiresConfirmation()
                        ->visible(fn (CompanyInquiry $r): bool => $r->status !== RfqStatus::Closed && static::canReview())
                        ->action(fn (CompanyInquiry $record) => static::run(fn () => app(InquiryTriageService::class)->close($record, auth()->user()), 'Inquiry closed')),
                ])->label('Triage')->icon('heroicon-m-ellipsis-vertical'),
            ]);
    }

    protected static function run(callable $callback, string $message): void
    {
        $callback();
        Notification::make()->title($message)->success()->send();
    }

    protected static function canReview(): bool
    {
        return (bool) auth()->user()?->can('inquiries.review');
    }
}
