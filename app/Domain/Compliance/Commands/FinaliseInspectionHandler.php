<?php

namespace App\Domain\Compliance\Commands;

use App\Domain\Compliance\Events\InspectionFinalised;
use App\Models\Inspection;
use App\Support\Bus\Command;
use App\Support\Bus\HandlesCommand;
use App\Support\Events\RecordsOutboxEvents;

/**
 * Thin seam over Inspection::finalise(). All the digital-signature
 * computation and the immutability guard live on the model itself — this
 * handler does not duplicate any of it, it just gives that behaviour a
 * Command/Bus entry point and records the InspectionFinalised domain event
 * to the outbox inside the same transaction CommandBus::dispatch() already
 * opens.
 */
final class FinaliseInspectionHandler implements HandlesCommand
{
    use RecordsOutboxEvents;

    public function handle(Command $command): Inspection
    {
        /** @var FinaliseInspectionCommand $command */
        $inspection = Inspection::findOrFail($command->inspectionId);

        if ($command->data !== []) {
            $inspection->fill($command->data);
            $inspection->save();
        }

        $inspection->finalise();

        $this->recordOutboxEvent(new InspectionFinalised(
            inspectionId: $inspection->getKey(),
            timberLotId: $inspection->timber_lot_id,
            orderId: $inspection->order_id,
            result: $inspection->result,
        ));

        return $inspection;
    }
}
