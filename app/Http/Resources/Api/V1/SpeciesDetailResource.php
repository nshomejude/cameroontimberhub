<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Species;
use Illuminate\Http\Request;

/**
 * The species detail screen: the card plus the long-form catalogue fields.
 *
 * @mixin Species
 */
class SpeciesDetailResource extends SpeciesResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'description' => $this->description,
            'local_names' => $this->local_names,
            'characteristics' => $this->characteristics,
            'typical_uses' => $this->typical_uses,
            'region_availability' => $this->region_availability,
            'swatch' => $this->swatch(),
        ]);
    }
}
