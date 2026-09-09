<?php

namespace App\Domain\Compliance\Commands;

use App\Domain\Compliance\Events\DisputeOpened;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use App\Services\DisputeService;
use App\Support\Bus\Command;
use App\Support\Bus\HandlesCommand;
use App\Support\Events\RecordsOutboxEvents;

/**
 * Thin seam over the existing dispute-opening logic. All the actual
 * party-resolution and validation lives in DisputeService::open() — this
 * handler is not a rewrite, it just gives that behaviour a Command/Bus entry
 * point and records the DisputeOpened domain event to the outbox inside the
 * same transaction CommandBus::dispatch() already opens.
 */
final class OpenDisputeHandler implements HandlesCommand
{
    use RecordsOutboxEvents;

    public function __construct(private readonly DisputeService $disputes) {}

    public function handle(Command $command): Dispute
    {
        /** @var OpenDisputeCommand $command */
        $order = Order::findOrFail($command->orderId);
        $actor = User::findOrFail($command->actingUserId);

        $dispute = $this->disputes->open($order, $actor, $command->category, $command->description);

        $this->recordOutboxEvent(new DisputeOpened(
            disputeId: $dispute->getKey(),
            orderId: $dispute->order_id,
            raisedByCompanyId: $dispute->raised_by_company_id,
            respondentCompanyId: $dispute->respondent_company_id,
        ));

        return $dispute;
    }
}
