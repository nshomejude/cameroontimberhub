<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\DocumentVisibility;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompanyDocument> */
class CompanyDocumentFactory extends Factory
{
    protected $model = CompanyDocument::class;

    public function definition(): array
    {
        return [
            'company_id'        => Company::factory(),
            'document_type_id'  => DocumentType::factory(),
            'original_filename' => $this->faker->slug(3).'.pdf',
            'storage_path'      => 'companies/test/'.$this->faker->uuid().'.pdf',
            'disk'              => 'documents',
            'mime_type'         => 'application/pdf',
            'file_size'         => $this->faker->numberBetween(10_000, 5_000_000),
            'checksum_sha256'   => hash('sha256', $this->faker->uuid()),
            'status'            => DocumentStatus::Pending,
            'visibility'        => DocumentVisibility::Private,
            'uploaded_by'       => User::factory(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => DocumentStatus::Approved]);
    }

    public function expiring(int $daysAhead = 20): static
    {
        return $this->state(fn () => [
            'status'      => DocumentStatus::Approved,
            'expiry_date' => now()->addDays($daysAhead)->toDateString(),
        ]);
    }
}
