<?php

namespace Database\Seeders;

use App\Models\GlossaryTerm;
use Illuminate\Database\Seeder;

/**
 * The first batch of the glossary — genuinely correct, standard timber-trade
 * definitions, not placeholder text. Idempotent via updateOrCreate on slug.
 * Grows toward the spec's 150-250 term target over subsequent editorial
 * passes; this seeder is the P0 core set, not the finished glossary.
 */
class GlossaryTermSeeder extends Seeder
{
    public function run(): void
    {
        $terms = [
            ['slug' => 'cbm', 'term' => 'CBM', 'definition' => 'Cubic metre — the standard unit of volume used to measure and price timber shipments.'],
            ['slug' => 'fob', 'term' => 'FOB', 'definition' => 'Free On Board — an Incoterm under which the seller\'s responsibility ends once the goods are loaded on board the vessel at the named port of shipment.'],
            ['slug' => 'cif', 'term' => 'CIF', 'definition' => 'Cost, Insurance and Freight — an Incoterm under which the seller pays the carriage and minimum insurance to the named destination port.'],
            ['slug' => 'kiln-dried', 'term' => 'Kiln Dried (KD)', 'definition' => 'Timber dried in a controlled-temperature chamber to a specified target moisture content, faster and more consistent than air drying.'],
            ['slug' => 'air-dried', 'term' => 'Air Dried (AD)', 'definition' => 'Timber dried naturally by stacking it with airflow between the boards, without mechanical heat.'],
            ['slug' => 'moisture-content', 'term' => 'Moisture Content', 'definition' => 'The weight of water in a piece of timber expressed as a percentage of its oven-dry weight; a key factor in stability and suitability for use.'],
            ['slug' => 'boules', 'term' => 'Boules', 'definition' => 'A log sawn through-and-through into boards that are kept in their original sawing sequence so the log can be reassembled — used to match grain across a project.'],
            ['slug' => 'sapwood', 'term' => 'Sapwood', 'definition' => 'The outer, living layer of wood in a growing tree, typically lighter in colour and less durable than heartwood.'],
            ['slug' => 'heartwood', 'term' => 'Heartwood', 'definition' => 'The inner, structurally inactive wood of a tree, generally denser, darker and more durable than sapwood.'],
            ['slug' => 'chain-of-custody', 'term' => 'Chain of Custody', 'definition' => 'The documented, traceable path of timber from harvest through processing and export to the final buyer, used to verify legal origin.'],
            ['slug' => 'moq', 'term' => 'MOQ', 'definition' => 'Minimum Order Quantity — the smallest volume a supplier will accept for a given order.'],
            ['slug' => 'grade', 'term' => 'Grade', 'definition' => 'A classification of timber quality based on visible defects, dimensions and appearance, used to set price and suitability for a given use.'],
            ['slug' => 'board-foot', 'term' => 'Board Foot', 'definition' => 'An imperial unit of lumber volume equal to a board 12 inches by 12 inches by 1 inch thick, still used in some export markets alongside CBM.'],
            ['slug' => 'container-loading', 'term' => 'Container Loading', 'definition' => 'The process of packing timber into a shipping container, optimised for volume, weight distribution and protection from moisture and damage in transit.'],
            ['slug' => 'phytosanitary-certificate', 'term' => 'Phytosanitary Certificate', 'definition' => 'An official document certifying that a timber shipment meets the plant-health import requirements of the destination country.'],
        ];

        foreach ($terms as $term) {
            GlossaryTerm::updateOrCreate(['slug' => $term['slug']], $term + ['is_published' => true]);
        }
    }
}
