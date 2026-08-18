<?php

namespace Database\Seeders;

use App\Enums\QuoteStatus;
use App\Enums\RfqStatus;
use App\Models\Company;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Species;
use App\Services\OrderService;
use App\Services\QuoteService;
use Illuminate\Database\Seeder;

/**
 * A second demo RFQ that has already been awarded, so the order, receipt and
 * verification screens have real data locally.
 *
 * It deliberately does NOT award QuoteSeeder's RFQ: awarding closes an RFQ and
 * declines its siblings, which would gut the quote-comparison screens.
 *
 * Idempotent: keyed on a fixed RFQ reference, and the award runs only when no
 * order exists yet. Re-running the seeder is a no-op.
 */
class OrderSeeder extends Seeder
{
    private const RFQ_REFERENCE = 'RFQ-DEMO-00002';

    public function run(): void
    {
        $companies = Company::query()
            ->whereIn('legal_name', [
                'Kuété Timber Group Sarl',
                'Sangha Forest Products Sarl',
            ])
            ->get();

        if ($companies->count() < 1) {
            return; // DemoCompanySeeder has not run — nothing to award to.
        }

        $species = Species::where('slug', 'iroko')->first() ?? Species::first();

        $rfq = Rfq::firstOrCreate(
            ['reference_code' => self::RFQ_REFERENCE],
            [
                'title' => 'Iroko sawn timber for decking contract',
                'project_name' => 'Antwerp marina decking',
                'buyer_name' => 'Marta Devos',
                'buyer_company' => 'Devos Hardwoods NV',
                'buyer_email' => 'orders@cameroontimberhub.test',
                'buyer_country_code' => 'BE',
                'destination_country_code' => 'BE',
                'incoterm' => 'FOB',
                'shipping_port' => 'Antwerp',
                'deadline' => now()->addDays(30)->toDateString(),
                'notes' => 'Kiln-dried to 12%. Please quote FOB Douala.',
                'status' => RfqStatus::Approved,
                'visibility' => 'public',
                'email_verified_at' => now(),
                'source' => 'seeder',
            ],
        );

        if ($rfq->items()->doesntExist()) {
            $rfq->items()->create([
                'species_id' => $species?->getKey(),
                'species_text' => $species ? null : 'Iroko',
                'form' => 'sawn',
                'grade' => 'Grade A',
                'dimensions' => '32mm x 145mm x 4000mm',
                'quantity' => 80,
                'unit' => 'm3',
                'moisture_content' => '12%',
            ]);
        }

        $rfqItem = $rfq->items()->first();

        $offers = [
            ['unit_price' => 240.00, 'lead_time_days' => 18, 'notes' => 'Stock available; we can load within three weeks of confirmation.'],
            ['unit_price' => 256.00, 'lead_time_days' => 25, 'notes' => 'Premium kiln-dried decking stock with full chain-of-custody paperwork.'],
        ];

        foreach ($companies->values() as $index => $company) {
            $routing = RfqCompany::firstOrCreate(
                ['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey()],
                ['status' => 'responded', 'routed_at' => now()->subDays(10), 'responded_at' => now()->subDays(7)],
            );

            if (Quote::where('rfq_id', $rfq->getKey())->where('company_id', $company->getKey())->exists()) {
                continue;
            }

            $offer = $offers[$index % count($offers)];

            $quote = Quote::create([
                'rfq_id' => $rfq->getKey(),
                'company_id' => $company->getKey(),
                'rfq_company_id' => $routing->getKey(),
                'reference_code' => 'QTE-DEMO-1'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'status' => QuoteStatus::Submitted,
                'currency' => 'USD',
                'incoterm' => 'FOB',
                'lead_time_days' => $offer['lead_time_days'],
                'validity_days' => 45,
                'valid_until' => now()->addDays(45)->toDateString(),
                'payment_terms' => '30% advance, 70% on delivery',
                'notes' => $offer['notes'],
                'submitted_at' => now()->subDays(7),
                'subtotal_amount' => 0,
                'total_amount' => 0,
            ]);

            $quote->items()->create([
                'rfq_item_id' => $rfqItem?->getKey(),
                'species_id' => $species?->getKey(),
                'description' => 'Sawn timber',
                'form' => 'sawn',
                'grade' => 'Grade A',
                'dimensions' => '32mm x 145mm x 4000mm',
                'quantity' => 80,
                'unit' => 'm3',
                'unit_price' => $offer['unit_price'],
                'line_total' => Quote::lineTotal(80, $offer['unit_price']),
            ]);

            $quote->load('items')->recalculateTotals()->save();
        }

        // Award the cheapest quote — through the real service, so the demo data
        // is produced by exactly the code path a buyer exercises.
        if ($rfq->orders()->exists()) {
            return;
        }

        $winner = Quote::where('rfq_id', $rfq->getKey())->open()->orderBy('total_amount')->first();

        if (! $winner) {
            return;
        }

        app(QuoteService::class)->accept($winner);

        // Walk the order a couple of real steps so the progress trail on the
        // order screen is not a single lonely milestone.
        $order = $rfq->orders()->latest('id')->first();
        $orders = app(OrderService::class);
        $orders->confirm($order);
        $orders->startProduction($order->refresh());
    }
}
