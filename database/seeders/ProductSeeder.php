<?php

namespace Database\Seeders;

use App\Enums\PriceUnit;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the demo marketplace catalogue used by the landing page ("Popular
 * Timber Products") and the product detail page. Idempotent — safe to re-run
 * in production; products are keyed on their slug.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $companies = Company::whereIn('slug', ['kuete-timber', 'sangha-forest'])->get()->keyBy('slug');
        $species = Species::whereIn('slug', ['iroko', 'sapele', 'tali', 'azobe', 'padouk', 'ayous'])
            ->get()->keyBy('slug');

        if ($companies->isEmpty()) {
            return; // DemoCompanySeeder has not run — nothing to attach products to.
        }

        foreach ($this->catalogue() as $row) {
            $company = $companies->get($row['company']);
            if (! $company) {
                continue;
            }

            unset($row['company']);
            $speciesSlug = $row['species'] ?? null;
            unset($row['species']);

            $images = $row['images'] ?? [];
            unset($row['images']);

            $product = Product::firstOrNew(['slug' => Str::slug($row['name'])]);

            $product->fill(array_merge([
                'company_id' => $company->id,
                'species_id' => $speciesSlug ? $species->get($speciesSlug)?->id : null,
                'price_currency' => 'XAF',
                'price_unit' => PriceUnit::CubicMetre,
                'moq_unit' => PriceUnit::CubicMetre,
                'origin' => 'Cameroon',
                'certification' => 'Legal Origin Verified',
                'status' => ProductStatus::Active,
            ], $row));

            // Seeders run under WithoutModelEvents, so Product::booted()'s
            // public_id hook does not fire — assign it explicitly on first
            // insert, the same way this seeder passes an explicit slug. Never
            // reassigned on re-run, so the id stays stable.
            if (blank($product->public_id)) {
                $product->public_id = \App\Support\ProductIdentifier::forProduct($product);
            }

            $product->save();

            foreach ($images as $i => $path) {
                $product->images()->firstOrCreate(['path' => $path], ['sort_order' => $i]);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function catalogue(): array
    {
        $irokoSpecs = [
            'botanical_name' => 'Milicia excelsa',
            'also_known' => 'Iroko, African Teak',
            'density' => '640 - 700 kg/m³',
            'durability_class' => 'Class 1 (Very Durable)',
            'strength' => 'High',
            'workability' => 'Good',
            'appearance' => 'Golden brown to dark brown',
            'common_uses' => 'Construction, Flooring, Furniture, Doors, Decking, Boat building',
            'packaging' => 'Bundles with steel/PP strapping',
            'delivery' => 'FOB Douala Port or CIF (Available)',
        ];

        return [
            [
                'company' => 'kuete-timber',
                'species' => 'iroko',
                'name' => 'Iroko Sawn Timber KD 50mm',
                'tagline' => 'Premium African Hardwood – Export Quality',
                'product_type' => ProductType::SawnTimber,
                'description' => 'High quality Iroko (Milicia excelsa) sawn timber, kiln dried to 12-15% moisture content. Strong, durable and ideal for construction, furniture and flooring. Our Iroko sawn timber is carefully kiln dried to ensure dimensional stability and suitability for a wide range of applications both indoors and outdoors.',
                'price_amount' => 650000,
                'moq_quantity' => 20,
                'grade' => 'Select & Better',
                'thickness_mm' => 50,
                'width_min_mm' => 100,
                'width_max_mm' => 250,
                'length_min_m' => 2.4,
                'length_max_m' => 6.0,
                'moisture_content' => '12% - 15% (KD)',
                'is_featured' => true,
                'is_best_seller' => true,
                'rating' => 4.8,
                'reviews_count' => 24,
                'buyers_count' => 128,
                'specifications' => $irokoSpecs,
                'key_benefits' => [
                    'Excellent strength and durability',
                    'Resistant to decay, termites and fungi',
                    'Beautiful golden brown appearance',
                    'Ideal for high-end construction and joinery',
                ],
                'primary_image_path' => 'products/iroko-sawn-timber.jpg',
                'images' => [
                    'products/iroko-sawn-timber.jpg',
                    'products/iroko-sawn-timber-38.jpg',
                    'products/iroko-sawn-timber-75.jpg',
                ],
            ],
            [
                'company' => 'kuete-timber',
                'species' => 'iroko',
                'name' => 'Iroko Sawn Timber KD 38mm',
                'product_type' => ProductType::SawnTimber,
                'description' => 'Kiln-dried Iroko sawn timber at 38mm thickness for joinery, doors and interior fit-out.',
                'price_amount' => 550000,
                'moq_quantity' => 20,
                'grade' => 'Select & Better',
                'thickness_mm' => 38,
                'moisture_content' => '12% - 15% (KD)',
                'specifications' => $irokoSpecs,
                'primary_image_path' => 'products/iroko-sawn-timber-38.jpg',
            ],
            [
                'company' => 'kuete-timber',
                'species' => 'iroko',
                'name' => 'Iroko Sawn Timber KD 75mm',
                'product_type' => ProductType::SawnTimber,
                'description' => 'Heavy-section kiln-dried Iroko for structural joinery, beams and marine applications.',
                'price_amount' => 720000,
                'moq_quantity' => 20,
                'grade' => 'Select & Better',
                'thickness_mm' => 75,
                'moisture_content' => '12% - 15% (KD)',
                'specifications' => $irokoSpecs,
                'primary_image_path' => 'products/iroko-sawn-timber-75.jpg',
            ],
            [
                'company' => 'kuete-timber',
                'species' => 'iroko',
                'name' => 'Iroko Logs',
                'product_type' => ProductType::Logs,
                'description' => 'Freshly felled Iroko logs from legally permitted concessions, graded and traceable to source.',
                'price_amount' => 320000,
                'moq_quantity' => 20,
                'grade' => 'LM/B',
                'moisture_content' => 'Green',
                'is_featured' => true,
                'rating' => 4.6,
                'reviews_count' => 11,
                'buyers_count' => 47,
                'primary_image_path' => 'products/iroko-logs.jpg',
            ],
            [
                'company' => 'kuete-timber',
                'species' => 'sapele',
                'name' => 'Sapele Veneer',
                'product_type' => ProductType::Veneer,
                'description' => 'Sliced Sapele decorative veneer with a pronounced ribbon figure, supplied in bundles.',
                'price_amount' => 4500,
                'price_unit' => PriceUnit::SquareMetre,
                'moq_quantity' => 500,
                'moq_unit' => PriceUnit::SquareMetre,
                'grade' => 'FAS',
                'thickness_mm' => 0.6,
                'is_featured' => true,
                'rating' => 4.7,
                'reviews_count' => 9,
                'buyers_count' => 32,
                'primary_image_path' => 'products/sapele-veneer.jpg',
            ],
            [
                'company' => 'sangha-forest',
                'species' => 'tali',
                'name' => 'Tali Flooring',
                'product_type' => ProductType::Flooring,
                'description' => 'Solid Tali tongue-and-groove flooring, extremely dense and hard wearing for commercial interiors.',
                'price_amount' => 780000,
                'moq_quantity' => 20,
                'grade' => 'Select & Better',
                'thickness_mm' => 20,
                'moisture_content' => '10% - 12% (KD)',
                'is_featured' => true,
                'rating' => 4.5,
                'reviews_count' => 7,
                'buyers_count' => 21,
                'primary_image_path' => 'products/tali-flooring.jpg',
            ],
            [
                'company' => 'sangha-forest',
                'species' => 'azobe',
                'name' => 'Azobe Decking',
                'product_type' => ProductType::Decking,
                'description' => 'Azobé decking boards for marine, hydraulic and heavy-duty exterior civil works.',
                'price_amount' => 890000,
                'moq_quantity' => 20,
                'grade' => 'FAS',
                'thickness_mm' => 45,
                'moisture_content' => 'Air dried',
                'is_featured' => true,
                'is_best_seller' => true,
                'rating' => 4.9,
                'reviews_count' => 15,
                'buyers_count' => 63,
                'primary_image_path' => 'products/azobe-decking.jpg',
            ],
            [
                'company' => 'sangha-forest',
                'species' => 'padouk',
                'name' => 'Padouk Mouldings',
                'product_type' => ProductType::Mouldings,
                'description' => 'Machined Padouk mouldings and profiles in a vivid red-orange tone for decorative joinery.',
                'price_amount' => 540000,
                'moq_quantity' => 10,
                'grade' => 'Select & Better',
                'moisture_content' => '10% - 12% (KD)',
                'is_featured' => true,
                'rating' => 4.4,
                'reviews_count' => 5,
                'buyers_count' => 18,
                'primary_image_path' => 'products/padouk-mouldings.jpg',
            ],
            [
                'company' => 'sangha-forest',
                'species' => 'ayous',
                'name' => 'Ayous Plywood',
                'product_type' => ProductType::Plywood,
                'description' => 'Lightweight Ayous-core plywood panels for packaging, furniture backing and core stock.',
                'price_amount' => 25000,
                'price_unit' => PriceUnit::Piece,
                'moq_quantity' => 200,
                'moq_unit' => PriceUnit::Piece,
                'thickness_mm' => 18,
                'primary_image_path' => 'products/ayous-plywood.jpg',
            ],
        ];
    }
}
