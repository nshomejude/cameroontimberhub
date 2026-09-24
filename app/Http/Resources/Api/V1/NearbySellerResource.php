<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;

/**
 * A SupplierResource card plus the fields the "nearest sellers" screen
 * needs: organisation type, coordinates and the distance from the query
 * point. Only rendered for Company::publiclyVisible() rows with coordinates.
 *
 * @mixin \App\Models\Company
 */
class NearbySellerResource extends SupplierResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'type' => $this->type?->value,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'distance_km' => round((float) $this->resource->getAttributes()['distance_km'], 1),
        ]);
    }
}
