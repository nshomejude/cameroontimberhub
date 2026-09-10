<?php

namespace App\Support;

use App\Models\CarbonProject;
use Illuminate\Support\Facades\DB;

/**
 * Builds a carbon project's public registry identifier: `CTH-CARB-{NNNNN}`
 * where `NNNNN` is a zero-padded, globally monotonic sequence.
 *
 * Collision-safe: the single counter row in
 * `carbon_project_public_id_sequences` is taken with `lockForUpdate` inside
 * a transaction, mirroring App\Support\ProductIdentifier (B1).
 *
 * Idempotent for a model: a project that already has a `public_id` keeps it.
 */
class CarbonProjectIdentifier
{
    public static function forProject(CarbonProject $project): string
    {
        if (filled($project->public_id)) {
            return (string) $project->public_id;
        }

        return self::next();
    }

    public static function next(): string
    {
        return DB::transaction(function (): string {
            $row = DB::table('carbon_project_public_id_sequences')->lockForUpdate()->first();

            if ($row === null) {
                $next = 1;
                DB::table('carbon_project_public_id_sequences')->insert(['last_value' => $next]);
            } else {
                $next = (int) $row->last_value + 1;
                DB::table('carbon_project_public_id_sequences')
                    ->where('id', $row->id)
                    ->update(['last_value' => $next]);
            }

            return sprintf('CTH-CARB-%05d', $next);
        });
    }
}
