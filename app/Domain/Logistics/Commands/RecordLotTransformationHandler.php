<?php

namespace App\Domain\Logistics\Commands;

use App\Domain\Logistics\Events\LotTransformationRecorded;
use App\Models\LotTransformation;
use App\Support\Bus\Command;
use App\Support\Bus\HandlesCommand;
use App\Support\Events\RecordsOutboxEvents;

/**
 * Thin seam over App\Models\LotTransformation::recordFor() — all the actual
 * mass-balance computation (totals/loss/ratio from line items, pivot
 * attachment of input/output lots) lives there and is not duplicated here.
 * CommandBus::dispatch() wraps this handle() call in DB::transaction();
 * recordFor() itself also opens a DB::transaction(), which nests as a
 * savepoint within the outer one — so the LotTransformationRecorded outbox
 * row recorded below still commits/rolls back atomically with the
 * transformation write.
 */
final class RecordLotTransformationHandler implements HandlesCommand
{
    use RecordsOutboxEvents;

    public function handle(Command $command): LotTransformation
    {
        /** @var RecordLotTransformationCommand $command */
        $transformation = LotTransformation::recordFor(
            processorCompanyId: $command->processorCompanyId,
            transformationType: $command->transformationType,
            inputs: $command->inputs,
            outputs: $command->outputs,
            processedAt: $command->processedAt,
            notes: $command->notes,
        );

        $this->recordOutboxEvent(new LotTransformationRecorded(
            lotTransformationId: $transformation->getKey(),
            processorCompanyId: $transformation->processor_company_id,
            transformationType: $transformation->transformation_type,
        ));

        return $transformation;
    }
}
