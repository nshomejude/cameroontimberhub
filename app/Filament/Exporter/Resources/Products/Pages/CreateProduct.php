<?php

namespace App\Filament\Exporter\Resources\Products\Pages;

use App\Domain\Catalog\Commands\PublishProductCommand;
use App\Enums\ProductStatus;
use App\Filament\Exporter\Resources\Products\ProductResource;
use App\Support\Bus\CommandBus;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Ownership is decided server-side — the form never supplies company_id,
     * even if a crafted request tries to smuggle one in.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $company = auth()->user()->companies()->firstOrFail();

        $data['company_id'] = $company->getKey();

        return $data;
    }

    /**
     * Architecture plan Phase 4 (Catalog context): when a supplier's new
     * listing is created straight into Active status, route the creation
     * through PublishProductCommand/CommandBus rather than Filament's
     * default `static::getModel()::create($data)`. The handler performs the
     * exact same Product::create($data) call, so behaviour is unchanged —
     * this only gives the "product went live" transition a Command/Bus
     * entry point (see App\Observers\ProductObserver, which still does all
     * the real work from the model's own created() event either way).
     */
    protected function handleRecordCreation(array $data): Model
    {
        if (($data['status'] ?? null) === ProductStatus::Active->value) {
            return app(CommandBus::class)->dispatch(new PublishProductCommand($data));
        }

        return parent::handleRecordCreation($data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
