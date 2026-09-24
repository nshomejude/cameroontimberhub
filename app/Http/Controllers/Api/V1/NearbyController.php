<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\NearbyIndexRequest;
use App\Http\Resources\Api\V1\NearbySellerResource;
use App\Models\Company;
use App\Support\Geo\Haversine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/nearby — public "nearest sellers" search for buyers.
 *
 * Reads through Company::publiclyVisible() (the single public-exposure gate),
 * so unverified, incomplete or badge-less companies never appear. Companies
 * without coordinates are skipped. Distance is a plain-SQL haversine (no
 * PostGIS) behind an index-friendly bounding-box pre-filter.
 */
class NearbyController extends Controller
{
    public function __invoke(NearbyIndexRequest $request): AnonymousResourceCollection
    {
        $lat = $request->lat();
        $lng = $request->lng();
        $radius = $request->radiusKm();
        $types = $request->types();

        [$distanceSql, $bindings] = Haversine::sql($lat, $lng, 'companies.latitude', 'companies.longitude');
        $box = Haversine::boundingBox($lat, $lng, $radius);

        $companies = Company::publiclyVisible()
            ->select('companies.*')
            ->selectRaw("{$distanceSql} as distance_km", $bindings)
            ->whereNotNull('companies.latitude')
            ->whereNotNull('companies.longitude')
            ->whereBetween('companies.latitude', [$box['minLat'], $box['maxLat']])
            ->whereBetween('companies.longitude', [$box['minLng'], $box['maxLng']])
            ->whereRaw("{$distanceSql} <= ?", [...$bindings, $radius])
            // No types[] = every seller type, plus companies whose
            // organisation type has not been backfilled yet (NULL). Buyer,
            // logistics, carbon, etc. organisations are never "sellers".
            ->when(
                $types !== [],
                fn (Builder $q) => $q->whereIn('companies.type', $types),
                fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->whereNull('companies.type')
                    ->orWhereIn('companies.type', NearbyIndexRequest::SELLER_TYPES)),
            )
            ->orderBy('distance_km')
            ->orderBy('companies.id')
            ->paginate($request->perPage())
            ->withQueryString();

        return NearbySellerResource::collection($companies);
    }
}
