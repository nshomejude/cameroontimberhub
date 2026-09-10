<?php

namespace App\Filament\Exporter\Resources\CarbonProjects\Pages;

use App\Enums\CarbonRegistryStatus;
use App\Filament\Exporter\Resources\CarbonProjects\CarbonProjectResource;
use App\Models\CarbonProject;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCarbonProject extends EditRecord
{
    protected static string $resource = CarbonProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submitForReview')
                ->label('Submit for review')
                ->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->visible(fn (CarbonProject $record): bool => ($record->registry_status ?? CarbonRegistryStatus::Draft)
                    ->canTransitionTo(CarbonRegistryStatus::Submitted))
                ->action(function (CarbonProject $record): void {
                    $record->transitionTo(CarbonRegistryStatus::Submitted);

                    Notification::make()
                        ->title('Carbon project submitted for review')
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }
}
