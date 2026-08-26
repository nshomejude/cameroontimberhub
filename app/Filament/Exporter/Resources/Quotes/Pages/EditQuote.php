<?php

namespace App\Filament\Exporter\Resources\Quotes\Pages;

use App\Enums\QuoteStatus;
use App\Filament\Exporter\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use App\Services\QuoteService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use RuntimeException;

class EditQuote extends EditRecord
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submit')
                ->label('Submit to buyer')
                ->icon('heroicon-m-paper-airplane')
                ->requiresConfirmation()
                ->modalDescription('The buyer will be emailed and can then accept or decline. Totals are recalculated from your line items.')
                ->visible(fn (): bool => $this->record->status === QuoteStatus::Draft)
                ->action(function (QuoteService $quotes) {
                    try {
                        $quotes->submit($this->record, auth()->user());
                        Notification::make()->title('Quote submitted')->success()->send();
                        $this->redirect(static::getResource()::getUrl('index'));
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    /** Money is never taken from the form — it is recomputed after every save. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['subtotal_amount'], $data['total_amount'], $data['status'], $data['company_id'], $data['reference_code']);

        return $data;
    }

    protected function afterSave(): void
    {
        app(QuoteService::class)->recalculate($this->record instanceof Quote ? $this->record : Quote::findOrFail($this->record->getKey()));
    }
}
