<?php

namespace App\Filament\Exporter\Resources\Products\Pages;

use App\Domain\Catalog\Commands\PublishProductCommand;
use App\Domain\Catalog\ProductPublishingRules;
use App\Enums\ProductStatus;
use App\Filament\Exporter\Resources\Products\Pages\Concerns\ShowsPublicVisibilityBanner;
use App\Filament\Exporter\Resources\Products\ProductResource;
use App\Models\Product;
use App\Support\Bus\CommandBus;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class EditProduct extends EditRecord
{
    use ShowsPublicVisibilityBanner;

    /** Primary image before this save — deleted from disk once replaced. */
    protected ?string $previousPrimaryImage = null;

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
        $this->previousPrimaryImage = $this->record->primary_image_path;

        return $data;
    }

    protected bool $justPublished = false;

    protected function afterSave(): void
    {
        $previous = $this->previousPrimaryImage;
        if (filled($previous) && $previous !== $this->record->primary_image_path && ! str_starts_with($previous, 'http')) {
            Storage::disk('public')->delete($previous);
        }
    }

    protected function getSavedNotification(): ?Notification
    {
        if ($this->justPublished) {
            return ProductResource::publishedNotification($this->record->company);
        }

        return parent::getSavedNotification();
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

        if (($data['status'] ?? null) === ProductStatus::Active->value && $record->status === ProductStatus::Archived) {
            Notification::make()->danger()
                ->title(__('An archived product cannot be published — move it back to draft first.'))
                ->send();
            $this->halt();
        }

        if ($isBecomingActive) {
            /** @var Product $record */
            $reason = ProductResource::publishBlockReason(
                $record->company,
                ProductPublishingRules::mergedAttributes($record, $data),
            );
            if ($reason !== null) {
                Notification::make()->danger()->title($reason)->send();
                $this->halt();
            }

            $this->justPublished = true;

            return app(CommandBus::class)->dispatch(new PublishProductCommand($data, $record->getKey()));
        }

        return parent::handleRecordUpdate($record, $data);
    }
}
