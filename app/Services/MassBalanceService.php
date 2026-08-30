<?php

namespace App\Services;

use App\Models\TimberLot;
use Illuminate\Support\Collection;

/**
 * Mass-balance trace-back (implementation blueprint §11): given an output
 * lot, walk backward through lot_transformation_outputs ->
 * lot_transformations -> lot_transformation_inputs to build the full set
 * of source lots that contributed to it. Recursive, since an input lot may
 * itself have been the output of an earlier transformation.
 */
class MassBalanceService
{
    public const MAX_DEPTH = 10;

    /**
     * @return Collection<int, TimberLot> distinct source lots that
     *   contributed (directly or transitively) to the given lot, keyed by
     *   lot id.
     */
    public function traceSources(TimberLot $lot): Collection
    {
        $visited = [];

        return $this->walk($lot, 0, $visited);
    }

    /**
     * @param  array<int, bool>  $visited  lot ids already visited, by reference, to guard against circular data
     * @return Collection<int, TimberLot>
     */
    protected function walk(TimberLot $lot, int $depth, array &$visited): Collection
    {
        $sources = collect();

        if ($depth >= self::MAX_DEPTH) {
            return $sources;
        }

        if (isset($visited[$lot->id])) {
            return $sources;
        }

        $visited[$lot->id] = true;

        $transformations = $lot->outputTransformations()->with('inputLots')->get();

        foreach ($transformations as $transformation) {
            foreach ($transformation->inputLots as $inputLot) {
                $sources->put($inputLot->id, $inputLot);

                $sources = $sources->union($this->walk($inputLot, $depth + 1, $visited));
            }
        }

        return $sources;
    }
}
