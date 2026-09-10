<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Rfq;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\Receipt>
 *
 * Builds a standalone receipt over a freshly minted awarded order. Receipts
 * are created through Eloquent, so every factory-made receipt runs the
 * `ChainsIntegrity` `creating` hook and lands with a valid hash chain.
 */
class ReceiptFactory extends Factory
{
    public function definition(): array
    {
        $order = $this->newOrder();

        return [
            'order_id' => $order->getKey(),
            'receipt_number' => 'RCT-'.date('Y').'-'.Str::upper(Str::random(6)),
            'verification_token' => Str::random(40),
            'issued_at' => now(),
            'amount' => $order->total_amount,
            'currency' => $order->currency,
        ];
    }

    private function newOrder(): Order
    {
        $quote = Quote::factory()->submitted()->create();
        $company = $quote->company ?? Company::factory()->verified()->create();

        return Order::create([
            'quote_id' => $quote->getKey(),
            'rfq_id' => $quote->rfq_id,
            'company_id' => $quote->company_id,
            'reference_code' => 'ORD-'.date('Y').'-'.Str::upper(Str::random(6)),
            'status' => 'awarded',
            'buyer_name' => $this->faker->name(),
            'buyer_email' => $this->faker->safeEmail(),
            'supplier_name' => $company->name ?? $this->faker->company(),
            'currency' => 'USD',
            'subtotal_amount' => 1250.00,
            'total_amount' => 1250.00,
            'awarded_at' => now(),
        ]);
    }
}
