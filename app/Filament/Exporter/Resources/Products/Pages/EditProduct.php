<?php

namespace App\Filament\Exporter\Resources\Products\Pages;

use App\Domain\Catalog\Commands\PublishProductCommand;
use App\Enums\ProductStatus;
use App\Filament\Exporter\Resources\Products\ProductResource;
use App\Support\Bus\CommandBus;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * company_id belongs to the owning company and is never editable from
     * the form — reassign it back to whatever it already was, regardless of
     * what a crafted request tries to submit.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['company_id'] = $this->record->company_id;

        return $data;
    }

    /**
     * Architecture plan Phase 4 (Catalog context): when this save actually
     * transitions the listing's status to Active, route it through
     * PublishProductCommand/CommandBus rather than Filament's default
     * `$record->update($data)`. The handler performs the exact same
     * `$record->update($data)` call, so behaviour is unchanged — this only
     * gives the "product went live" transition a Command/Bus entry point
     * (App\Observers\ProductObserver still does all the real work from the
     * model's own updated() event either way).
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $isBecomingActive = ($data['status'] ?? null) === ProductStatus::Active->value
            && $record->status !== ProductStatus::Active;

        if ($isBecomingActive) {
            return app(CommandBus::class)->dispatch(new PublishProductCommand($data, $record->getKey()));
        }

        return parent::handleRecordUpdate($record, $data);
    }
}
