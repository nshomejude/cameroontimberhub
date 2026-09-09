<?php

namespace App\Domain\Catalog\Commands;

use App\Models\Product;
use App\Support\Bus\Command;
use App\Support\Bus\HandlesCommand;

/**
 * Thin seam over the existing Product create/save flow. All the real "a
 * product went live" behaviour still lives in App\Observers\ProductObserver
 * (TimberLot creation/archival, pricing-anomaly detection, and — as of this
 * change — the `product.published` outbox event) which fires from
 * Product's own created()/updated() Eloquent events exactly as it does when
 * Filament's default CreateRecord/EditRecord lifecycle calls
 * Product::create()/$record->update() directly. This handler does not
 * duplicate or reinterpret that logic, it only gives it a Command/Bus entry
 * point, run inside CommandBus's DB transaction so the outbox row recorded
 * by the Observer stays transactionally tied to the state change.
 */
final class PublishProductHandler implements HandlesCommand
{
    public function handle(Command $command): Product
    {
        /** @var PublishProductCommand $command */
        if ($command->productId !== null) {
            $product = Product::findOrFail($command->productId);
            $product->update($command->data);

            return $product;
        }

        return Product::create($command->data);
    }
}
