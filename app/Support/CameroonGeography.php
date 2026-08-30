<?php

namespace App\Support;

/**
 * Static reference data for Cameroon's 10 regions and their major cities
 * and towns, plus an approximate lat/lng centre per region.
 *
 * Company.region/city are free-text columns (see the companies table
 * migration) with no FK to a locations table, so this is a plain reference
 * list rather than a model -- used to (a) always offer the full set of
 * regions/towns as filter options on public directories, even where no
 * company/product data exists for them yet, and (b) resolve a browser
 * geolocation coordinate to the nearest region for "use my location"
 * filtering (see DomesticMarketplaceController).
 *
 * Region centre coordinates are the regional capital's approximate
 * lat/lng -- accurate enough for nearest-region matching, not for
 * anything requiring survey-grade precision.
 */
class CameroonGeography
{
    /**
     * @return array<string, array{lat: float, lng: float, cities: list<string>}>
     */
    public static function regions(): array
    {
        return [
            'Adamawa' => [
                'lat' => 7.3167, 'lng' => 13.5833,
                'cities' => ['Ngaoundéré', 'Meiganga', 'Tibati', 'Tignère', 'Banyo', 'Ngaoundal', 'Djohong'],
            ],
            'Centre' => [
                'lat' => 3.8480, 'lng' => 11.5021,
                'cities' => ['Yaoundé', 'Mbalmayo', 'Obala', 'Nanga-Eboko', 'Bafia', 'Akonolinga', 'Monatélé', 'Ntui', 'Eséka', "Sa'a", 'Mfou', 'Soa'],
            ],
            'East' => [
                'lat' => 4.5776, 'lng' => 13.6846,
                'cities' => ['Bertoua', 'Abong-Mbang', 'Batouri', 'Yokadouma', 'Bélabo', 'Doumé', 'Garoua-Boulaï', 'Moloundou', 'Lomié'],
            ],
            'Far North' => [
                'lat' => 10.5913, 'lng' => 14.3153,
                'cities' => ['Maroua', 'Kousséri', 'Mokolo', 'Yagoua', 'Kaélé', 'Mora', 'Waza', 'Mindif'],
            ],
            'Littoral' => [
                'lat' => 4.0511, 'lng' => 9.7679,
                'cities' => ['Douala', 'Nkongsamba', 'Edéa', 'Loum', 'Manjo', 'Yabassi', 'Dizangué', 'Mbanga'],
            ],
            'North' => [
                'lat' => 9.3017, 'lng' => 13.3921,
                'cities' => ['Garoua', 'Guider', 'Poli', 'Rey Bouba', 'Tcholliré', 'Pitoa', 'Figuil'],
            ],
            'Northwest' => [
                'lat' => 5.9631, 'lng' => 10.1591,
                'cities' => ['Bamenda', 'Kumbo', 'Wum', 'Ndop', 'Fundong', 'Mbengwi', 'Nkambe', 'Batibo'],
            ],
            'South' => [
                'lat' => 2.9167, 'lng' => 11.1500,
                'cities' => ['Ebolowa', 'Kribi', 'Sangmélima', 'Ambam', 'Djoum', 'Mvangan', 'Campo'],
            ],
            'Southwest' => [
                'lat' => 4.1560, 'lng' => 9.2320,
                'cities' => ['Buea', 'Limbe', 'Kumba', 'Tiko', 'Mamfe', 'Muyuka', 'Idenau', 'Ekondo Titi'],
            ],
            'West' => [
                'lat' => 5.4737, 'lng' => 10.4176,
                'cities' => ['Bafoussam', 'Dschang', 'Mbouda', 'Foumban', 'Bandjoun', 'Bafang', 'Foumbot', 'Bangangté'],
            ],
        ];
    }

    /** @return list<string> All 10 region names, alphabetical. */
    public static function regionNames(): array
    {
        return array_keys(self::regions());
    }

    /** @return list<string> Every city/town across all regions, alphabetical. */
    public static function allCities(): array
    {
        $cities = array_merge(...array_column(self::regions(), 'cities'));
        sort($cities);

        return array_values(array_unique($cities));
    }

    /** @return list<string> Cities/towns for a single region, or [] if the region is unknown. */
    public static function citiesFor(string $region): array
    {
        return self::regions()[$region]['cities'] ?? [];
    }

    /**
     * Nearest region to a given coordinate, by straight-line distance to
     * each region's centre. Good enough for "use my location" filtering --
     * not meant to resolve exact administrative boundaries.
     */
    public static function nearestRegion(float $lat, float $lng): string
    {
        $closest = null;
        $closestDistance = null;

        foreach (self::regions() as $name => $data) {
            $distance = self::haversineKm($lat, $lng, $data['lat'], $data['lng']);

            if ($closestDistance === null || $distance < $closestDistance) {
                $closestDistance = $distance;
                $closest = $name;
            }
        }

        return $closest;
    }

    private static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
