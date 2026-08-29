<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'parent_id' => null,
            'kind' => 'form',
            'slug' => $this->faker->unique()->slug(2),
            'name' => $this->faker->words(2, true),
        ];
    }
}
