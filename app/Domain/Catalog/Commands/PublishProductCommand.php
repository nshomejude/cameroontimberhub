<?php

namespace App\Domain\Catalog\Commands;

use App\Support\Bus\Command;

/**
 * Create or save a supplier's Product listing through the CommandBus. Thin
 * DTO — carries only the form data the Filament Products resource already
 * builds (see App\Filament\Exporter\Resources\Products\Pages\{Create,Edit}Product),
 * plus the existing record's id for an update. It does not duplicate any
 * business rules: the handler performs the exact same
 * `Product::create($data)` / `$record->update($data)` Filament would have
 * done itself, so ProductObserver's created()/updated() callbacks (and,
 * inside those, the "product went live" TimberLot + outbox-event wiring)
 * fire exactly as they do today — this command is a routing seam, not a
 * rewrite of what "publishing" means.
 */
final class PublishProductCommand implements Command
{
    public function __construct(
        public readonly array $data,
        public readonly ?int $productId = null,
    ) {}
}
