<?php

namespace App\Models;

use App\Models\Concerns\HasCheckpointUpdates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A transport booking (gap-plan 1.5.10). Owns a digital waybill; carries no
 * status/location fields — checkpoint tracking (1.5.11) is a separate,
 * polymorphic `CheckpointUpdate` model that attaches to this later.
 *
 * `vehicle_id`/`driver_id` are optional and unconstrained (see migration
 * doc) — always guard with `?->` / null checks, never assume a fleet row
 * exists.
 */
class Shipment extends Model
{
    use HasFactory;
    /**
     * Gives this model `checkpointUpdates()`/`latestCheckpoint()` (gap-plan
     * 1.5.11 wiring, blueprint §45-46). Shipment was always the intended
     * first real consumer of this trait (see the trait's own doc block) —
     * this is that follow-up, added by the offline field-capture endpoint
     * at App\Http\Controllers\Public\LogisticsCheckpointController.
     */
    use HasCheckpointUpdates;

    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'waybill_number';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** TimberLots carried on this shipment (blueprint §10 wiring), with the m3 quantity on this leg. */
    public function timberLots(): BelongsToMany
    {
        return $this->belongsToMany(TimberLot::class, 'shipment_timber_lots')->withPivot('quantity_m3');
    }

    /**
     * Product IDs of the cargo, resolved from the booking order's line
     * items. Order items do not carry a `product_id` (they snapshot a
     * quote line: species + description, not a catalogue Product row), so
     * each item is matched back to a live Product by the order's supplier
     * company + species. An item with no matching Product (deleted
     * listing, or a species not catalogued as a Product) is silently
     * skipped rather than erroring — mirrors InventoryService's opt-in,
     * no-matching-row-is-a-no-op pattern.
     */
    public function cargoProductIds(): array
    {
        return $this->order->items
            ->map(fn (OrderItem $item) => Product::query()
                ->where('company_id', $this->order->company_id)
                ->where('species_id', $item->species_id)
                ->value('id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
