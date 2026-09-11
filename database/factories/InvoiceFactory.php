<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Support\InvoiceNumberGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Invoice>
 *
 * Invoices are created through Eloquent, so every factory-made invoice runs
 * the ChainsIntegrity `creating` hook and lands with a valid hash chain.
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        $subtotal = $this->faker->numberBetween(10000, 900000).'.00';

        return [
            'invoice_number' => app(InvoiceNumberGenerator::class)->invoice(),
            'company_id' => Company::factory(),
            'status' => InvoiceStatus::Paid,
            'currency' => 'XAF',
            'subtotal_amount' => $subtotal,
            'tax_amount' => '0.00',
            'total_amount' => $subtotal,
            'bill_to' => ['legal_name' => $this->faker->company()],
            'bill_from' => ['organisation' => 'Cameroon Timber Hub'],
            'issued_at' => now(),
        ];
    }
}
