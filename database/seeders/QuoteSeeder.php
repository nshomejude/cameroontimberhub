<?php

namespace Database\Seeders;

use App\Enums\QuoteStatus;
use App\Enums\RfqStatus;
use App\Models\Company;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Species;
use Illuminate\Database\Seeder;

/**
 * A demo RFQ routed to three verified exporters, each with a submitted quote,
 * so the buyer responses and quote-detail screens have real data locally.
 *
 * Idempotent: keyed on a fixed RFQ reference and one quote per supplier.
 */
class QuoteSeeder extends Seeder
{
    private const RFQ_REFERENCE = 'RFQ-DEMO-00001';

    public function run(): void
    {
        $companies = Company::query()
            ->whereIn('legal_name', [
                'Kuété Timber Group Sarl',
                'Sangha Forest Products Sarl',
                'Pallisco Cameroon Sarl',
            ])
            ->get();

        if ($companies->isEmpty()) {
            return; // DemoCompanySeeder has not run — nothing to quote against.
        }

        $species = Species::where('slug', 'sapele')->first() ?? Species::first();

        $rfq = Rfq::firstOrCreate(
            ['reference_code' => self::RFQ_REFERENCE],
            [
                'title' => 'Sapele sawn timber for Q3 joinery contract',
                'project_name' => 'Rotterdam joinery fit-out',
                'buyer_name' => 'Pro Buyer',
                'buyer_company' => 'Northgate Joinery BV',
                'buyer_email' => 'buyer@cameroontimberhub.test',
                'buyer_country_code' => 'NL',
                'destination_country_code' => 'NL',
                'incoterm' => 'FOB',
                'shipping_port' => 'Rotterdam',
                'deadline' => now()->addDays(21)->toDateString(),
                'notes' => 'Kiln-dried, FSC-preferred. Please quote FOB Douala.',
                'status' => RfqStatus::Approved,
                'visibility' => 'public',
                'email_verified_at' => now(),
                'source' => 'seeder',
            ],
        );

        if ($rfq->items()->doesntExist()) {
            $rfq->items()->create([
                'species_id' => $species?->getKey(),
                'species_text' => $species ? null : 'Sapele',
                'form' => 'sawn',
                'grade' => 'Grade A',
                'dimensions' => '50mm x 150mm x 3000mm',
                'quantity' => 100,
                'unit' => 'm3',
                'moisture_content' => '12%',
            ]);
        }

        $rfqItem = $rfq->items()->first();

        // Three suppliers, three genuinely different offers.
        $offers = [
            ['unit_price' => 185.00, 'lead_time_days' => 15, 'shipping' => null, 'notes' => 'Thank you for the opportunity to quote. Stock is available and we can load within two weeks of order confirmation.'],
            ['unit_price' => 172.50, 'lead_time_days' => 32, 'shipping' => 1800.00, 'notes' => 'Our price is keen but the mill is booked into next month, hence the longer lead time.'],
            ['unit_price' => 199.00, 'lead_time_days' => 12, 'shipping' => null, 'notes' => 'Premium kiln-dried stock, FSC chain-of-custody certificates supplied with every shipment.'],
        ];

        foreach ($companies->values() as $index => $company) {
            $routing = RfqCompany::firstOrCreate(
                ['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey()],
                ['status' => 'responded', 'routed_at' => now()->subDays(6), 'responded_at' => now()->subDays(3)],
            );

            $exists = Quote::where('rfq_id', $rfq->getKey())->where('company_id', $company->getKey())->exists();

            if ($exists) {
                continue;
            }

            $offer = $offers[$index % count($offers)];

            $quote = Quote::create([
                'rfq_id' => $rfq->getKey(),
                'company_id' => $company->getKey(),
                'rfq_company_id' => $routing->getKey(),
                'reference_code' => 'QTE-DEMO-'.str_pad((string) ($index + 1), 5, '0', STR_PAD_LEFT),
                'status' => QuoteStatus::Submitted,
                'currency' => 'USD',
                'incoterm' => 'FOB',
                'lead_time_days' => $offer['lead_time_days'],
                'validity_days' => 30,
                'valid_until' => now()->addDays(30)->toDateString(),
                'payment_terms' => '30% advance, 70% on delivery',
                'notes' => $offer['notes'],
                'shipping_amount' => $offer['shipping'],
                'submitted_at' => now()->subDays(3),
                'subtotal_amount' => 0,
                'total_amount' => 0,
            ]);

            $quote->items()->create([
                'rfq_item_id' => $rfqItem?->getKey(),
                'species_id' => $species?->getKey(),
                'description' => 'Sawn timber',
                'form' => 'sawn',
                'grade' => 'Grade A',
                'dimensions' => '50mm x 150mm x 3000mm',
                'quantity' => 100,
                'unit' => 'm3',
                'unit_price' => $offer['unit_price'],
                'line_total' => Quote::lineTotal(100, $offer['unit_price']),
            ]);

            // Totals always come from the line items, seeder included.
            $quote->load('items')->recalculateTotals()->save();
        }
    }
}
