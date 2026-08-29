<?php

namespace Database\Factories;

use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentType> */
class DocumentTypeFactory extends Factory
{
    protected $model = DocumentType::class;

    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->slug(2),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'is_required' => false,
            'requires_expiry' => false,
            'affects_verification' => true,
            'supports_sigif' => false,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
