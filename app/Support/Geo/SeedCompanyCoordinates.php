<?php

namespace App\Support\Geo;

use App\Models\Company;

/**
 * Realistic Cameroon coordinates (and a short street-level address) for
 * every company created by the demo/directory seeders, keyed by slug.
 * Points sit in the company's city, spread a little so same-city sellers
 * do not stack on one pin. Used by CompanyCoordinatesSeeder and the
 * `companies:backfill-coordinates` command — the one source of truth.
 */
final class SeedCompanyCoordinates
{
    /** @var array<string, array{0: float, 1: float, 2: string}> slug => [lat, lng, address] */
    public const MAP = [
        // DemoCompanySeeder — persona + directory companies
        'kuete-timber' => [4.043712, 9.686420, 'Zone Portuaire'],
        'sangha-forest' => [4.577815, 13.684590, 'Quartier Mokolo, Route de Batouri'],
        'pallisco-cameroon' => [4.061230, 9.735110, 'Zone Industrielle de Bassa'],
        'sifor-timber' => [2.942870, 9.908540, 'Route du Port en Eau Profonde'],
        'cfc-wood-industry' => [5.477310, 10.417950, 'Zone Industrielle de Bafoussam'],
        'pending-mill' => [3.866840, 11.516210, 'Quartier Mvan'],
        'african-wood-exporters' => [4.050690, 9.767230, 'Boulevard de la Réunification, Bonabéri'],
        'bois-et-nature-cameroon' => [3.848030, 11.502170, 'Quartier Nsam'],
        'green-forest-industries' => [2.911620, 11.151300, 'Route de Kribi'],
        'cameroon-timber-co' => [2.950240, 9.918810, 'Quartier Afan Mabé'],
        'natural-timber-solutions' => [5.470040, 10.425110, 'Quartier Tamdja'],
        'atlas-wood-exports' => [9.301450, 13.397820, 'Zone Industrielle de Garoua'],
        'equator-timber' => [4.029580, 9.709150, 'Quartier Akwa Nord'],
        'cemac-wood-traders' => [3.880510, 11.528990, 'Quartier Nlongkak'],

        // DemoLoginSeeder — role personas
        'kuete-timber-group-sarl' => [4.043712, 9.686420, 'Zone Portuaire'],
        'bantu-freight-logistics-sarl' => [4.036400, 9.698020, 'Rue des Docks, Bonanjo'],
        'nkolbisson-timber-traders-sarl' => [3.874480, 11.450340, 'Quartier Nkolbisson'],
        'sanaga-sawmill-sarl' => [3.797560, 10.132900, 'Route de la Sanaga'],
        'mvog-betsi-furniture-works-sarl' => [3.853120, 11.483950, 'Quartier Mvog-Betsi'],
        'atelier-ebang-menuiserie' => [5.484950, 10.410730, 'Quartier Djeleng'],
        'marche-mokolo-timber-yard-sarl' => [3.874910, 11.500780, 'Marché Mokolo'],
        'dja-forest-carbon-sarl' => [2.668010, 12.804130, 'Centre-ville de Djoum'],

        // DomesticMarketDemoSeeder
        'yaounde-sawmill-co-op' => [3.838650, 11.531260, 'Quartier Ahala'],
        'bafoussam-timber-processing' => [5.492180, 10.405260, 'Route de Foumban'],
        'douala-wood-transformation-hub' => [4.073540, 9.742880, 'Zone Industrielle de Ndokoti'],
        'bamenda-furniture-workshop' => [5.959740, 10.145920, 'Commercial Avenue'],
        'douala-artisan-crafts' => [4.052160, 9.721470, 'Quartier Deïdo'],
        'highland-transport-services' => [5.953080, 10.160530, 'Nkwen'],
        'cameroon-freight-logistics' => [4.041580, 9.713390, 'Quartier Bali'],

        // DomesticServiceDemoSeeder
        'adamawa-reforestation-initiative' => [7.322610, 13.584370, 'Quartier Baladji'],
        'east-cameroon-forest-carbon' => [4.582570, 13.672100, 'Quartier Ndemba'],
        'south-region-carbon-forestry' => [2.905830, 11.143950, 'Quartier Angalé'],
    ];

    /** @return int number of companies updated */
    public static function apply(bool $force = false): int
    {
        $updated = 0;

        foreach (self::MAP as $slug => [$lat, $lng, $address]) {
            $company = Company::query()->where('slug', $slug)->first();

            if ($company === null) {
                continue;
            }

            if ($force || $company->latitude === null || $company->longitude === null) {
                $company->latitude = $lat;
                $company->longitude = $lng;
            }

            if (blank($company->address_line)) {
                $company->address_line = $address;
            }

            if ($company->isDirty()) {
                $company->saveQuietly();
                $updated++;
            }
        }

        return $updated;
    }
}
