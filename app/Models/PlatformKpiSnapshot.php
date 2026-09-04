<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A daily point-in-time snapshot of the North-Star platform KPIs (blueprint
 * §66-69), written by the `platform:kpi-snapshot` console command. `date` is
 * unique, so the command upserts one row per calendar day rather than
 * accumulating duplicates on re-run.
 */
class PlatformKpiSnapshot extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'metrics' => 'array',
        ];
    }
}
