<?php

namespace App\Domain\Logistics\Commands;

use App\Support\Bus\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Record a checkpoint against any trackable (Shipment today — the only
 * caller is App\Http\Controllers\Public\LogisticsCheckpointController, which
 * always passes a Shipment, but this stays as generic as
 * App\Services\CheckpointTracker::record() itself). Thin DTO — carries the
 * trackable model plus the same allow-listed field set CheckpointTracker
 * already accepts; it does not duplicate any of its token issuance/reuse or
 * `occurred_at` handling.
 */
final class RecordCheckpointCommand implements Command
{
    /**
     * @param  array<string, mixed>  $data  status, location, latitude,
     *         longitude, notes, occurred_at, recorded_by, photo_path — passed
     *         through verbatim to CheckpointTracker::record().
     */
    public function __construct(
        public readonly Model $trackable,
        public readonly array $data,
    ) {}
}
