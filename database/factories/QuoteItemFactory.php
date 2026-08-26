<?php

namespace Database\Factories;

use App\Models\Quote;
use App\Models\QuoteItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QuoteItem> */
class QuoteItemFactory extends Factory
{
    protected $model = QuoteItem::class;

    public function definition(): array
    {
        $quantity = $this->faker->numberBetween(10, 200);
        $unitPrice = $this->faker->numberBetween(120, 900);

        return [
            'quote_id' => Quote::factory(),
            'description' => $this->faker->randomElement(['Sawn timber', 'Logs', 'Veneer sheets']),
            'form' => 'sawn',
            'grade' => 'Grade A',
            'dimensions' => '50mm x 150mm x 3000mm',
            'quantity' => $quantity,
            'unit' => 'm3',
            'unit_price' => $unitPrice,
            'line_total' => Quote::lineTotal($quantity, $unitPrice),
        ];
    }
}
