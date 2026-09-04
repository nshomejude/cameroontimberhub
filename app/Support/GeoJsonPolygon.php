<?php

namespace App\Support;

/**
 * Validation helper for a plot/harvest-area boundary stored on TimberLot
 * (implementation blueprint §12) as a portable jsonb GeoJSON Polygon —
 * deliberately NOT a PostGIS geometry column, to avoid infra risk.
 *
 * A minimal, defensive check: we are not a full GeoJSON validator, just
 * enough structural sanity to catch obviously-malformed input before it
 * lands in the database.
 */
class GeoJsonPolygon
{
    public static function isValid(array $geojson): bool
    {
        if (($geojson['type'] ?? null) !== 'Polygon') {
            return false;
        }

        $coordinates = $geojson['coordinates'] ?? null;

        if (! is_array($coordinates) || $coordinates === []) {
            return false;
        }

        foreach ($coordinates as $ring) {
            if (! self::isValidRing($ring)) {
                return false;
            }
        }

        return true;
    }

    private static function isValidRing(mixed $ring): bool
    {
        if (! is_array($ring) || count($ring) < 4) {
            return false;
        }

        foreach ($ring as $point) {
            if (! self::isValidPoint($point)) {
                return false;
            }
        }

        $first = $ring[0];
        $last = $ring[count($ring) - 1];

        return $first === $last;
    }

    private static function isValidPoint(mixed $point): bool
    {
        return is_array($point)
            && count($point) === 2
            && is_numeric($point[0] ?? null)
            && is_numeric($point[1] ?? null);
    }
}
