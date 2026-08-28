<?php

namespace Database\Factories;

use App\Models\Certificate;
use App\Models\CertificateAllocation;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The consumer column is polymorphic; a Quote is used here only because it
 * is a real commercial commitment that HAS a factory. Order -- the consumer
 * this ledger is ultimately for -- is deliberately only creatable through
 * OrderService::createFromQuote() and so has no factory of its own.
 *
 * @extends Factory<CertificateAllocation>
 */
class CertificateAllocationFactory extends Factory
{
    protected $model = CertificateAllocation::class;

    public function definition(): array
    {
        return [
            'certificate_id' => Certificate::factory(),
            'consumer_type' => Quote::class,
            'consumer_id' => Quote::factory(),
            'quantity' => $this->faker->randomFloat(3, 1, 20),
        ];
    }
}
