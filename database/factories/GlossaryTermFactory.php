<?php

namespace Database\Factories;

use App\Models\GlossaryTerm;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GlossaryTerm>
 */
class GlossaryTermFactory extends Factory
{
    protected $model = GlossaryTerm::class;

    public function definition(): array
    {
        $term = ucfirst($this->faker->unique()->words(2, true));

        return [
            'slug' => Str::slug($term).'-'.$this->faker->unique()->numberBetween(1, 999999),
            'term' => $term,
            'definition' => $this->faker->sentence(12),
            'explanation' => $this->faker->paragraph(),
            'related_term_ids' => [],
            'related_species_ids' => [],
            'is_published' => true,
        ];
    }

    public function unpublished(): static
    {
        return $this->state(fn (): array => ['is_published' => false]);
    }
}
