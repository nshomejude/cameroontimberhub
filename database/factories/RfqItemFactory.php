<?php

namespace Database\Factories;

use App\Enums\TimberForm;
use App\Models\Rfq;
use App\Models\RfqItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RfqItem> */
class RfqItemFactory extends Factory
{
    protected $model = RfqItem::class;

    public function definition(): array
    {
        return [
            'rfq_id'      => Rfq::factory(),
            'species_text' => $this->faker->randomElement(['Iroko', 'Sapele', 'Okoumé', 'Azobé', 'Moabi']),
            'form'        => $this->faker->randomElement(TimberForm::cases()),
            'quantity'    => $this->faker->numberBetween(5, 500),
            'unit'        => $this->faker->randomElement(['m3', 'ton', 'pcs', 'container']),
        ];
    }
}
