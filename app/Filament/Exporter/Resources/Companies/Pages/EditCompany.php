<?php

namespace App\Filament\Exporter\Resources\Companies\Pages;

use App\Enums\CompanyStatus;
use App\Filament\Exporter\Resources\Companies\CompanyResource;
use App\Services\CompanyCompletenessService;
use App\Services\CompanyStatusService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submitForReview')
                ->label('Submit for review')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn (): bool => in_array(
                    $this->record->status,
                    [CompanyStatus::Draft, CompanyStatus::Rejected],
                    true,
                ))
                ->requiresConfirmation()
                ->modalDescription('We will review your profile and documents. Your profile must be complete first.')
                ->action(function (): void {
                    $readiness = app(CompanyCompletenessService::class)->readyForSubmission($this->record);

                    if (! $readiness->isComplete()) {
                        Notification::make()
                            ->title('Profile incomplete')
                            ->body('Still needed: '.implode(', ', $readiness->missing))
                            ->warning()
                            ->send();

                        return;
                    }

                    app(CompanyStatusService::class)->submit($this->record, auth()->user());

                    Notification::make()->title('Submitted for review')->success()->send();

                    $this->redirect(static::getResource()::getUrl('edit', ['record' => $this->record]));
                }),
        ];
    }
}
