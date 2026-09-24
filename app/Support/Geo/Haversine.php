<?php

namespace App\Support\Geo;

/**
 * Great-circle distance in plain SQL (Postgres trig functions), so the
 * "nearest sellers" queries need no PostGIS. The expression is returned with
 * its own positional bindings so callers can use it in selectRaw/whereRaw/
 * orderByRaw without string-interpolating user input.
 */
final class Haversine
{
    public const EARTH_RADIUS_KM = 6371.0;

    /**
     * @return array{0: string, 1: list<float>} SQL expression (km) and bindings
     */
    public static function sql(float $lat, float $lng, string $latColumn = 'latitude', string $lngColumn = 'longitude'): array
    {
        // least(1, ...) guards asin() against floating-point overshoot.
        $sql = sprintf(
            '(%1$F * 2 * asin(least(1, sqrt(power(sin(radians(%2$s - ?) / 2), 2) + cos(radians(?)) * cos(radians(%2$s)) * power(sin(radians(%3$s - ?) / 2), 2)))))',
            self::EARTH_RADIUS_KM,
            $latColumn,
            $lngColumn,
        );

        return [$sql, [$lat, $lat, $lng]];
    }

    /**
     * A lat/lng bounding box that fully contains the radius circle — a cheap,
     * index-friendly pre-filter before the exact haversine check.
     *
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     */
    public static function boundingBox(float $lat, float $lng, float $radiusKm): array
    {
        $dLat = rad2deg($radiusKm / self::EARTH_RADIUS_KM);
        $cos = cos(deg2rad($lat));
        $dLng = $cos < 1e-6 ? 180.0 : rad2deg($radiusKm / (self::EARTH_RADIUS_KM * $cos));

        return [
            'minLat' => max(-90.0, $lat - $dLat),
            'maxLat' => min(90.0, $lat + $dLat),
            'minLng' => $dLng >= 180.0 ? -180.0 : $lng - $dLng,
            'maxLng' => $dLng >= 180.0 ? 180.0 : $lng + $dLng,
        ];
    }
}
