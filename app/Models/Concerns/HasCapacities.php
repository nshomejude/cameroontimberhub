<?php

namespace App\Models\Concerns;

use App\Models\Capacity;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasCapacities
{
    public function capacities(): MorphMany
    {
        return $this->morphMany(Capacity::class, 'owner');
    }
}
