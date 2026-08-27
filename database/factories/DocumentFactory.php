<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Species;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'owner_type' => Species::class,
            'owner_id' => Species::factory(),
            'type' => 'source_citation',
            'original_filename' => $this->faker->slug().'.pdf',
            'disk' => 'documents',
            'storage_path' => 'documents/'.now()->format('Y/m').'/'.$this->faker->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => $this->faker->numberBetween(1024, 2_000_000),
            'issuer' => $this->faker->company(),
            'issued_at' => $this->faker->dateTimeBetween('-2 years', 'now'),
            'uploaded_by' => null,
        ];
    }
}
