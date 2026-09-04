<?php

namespace App\Observers;

use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceRule;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wires real Order creation into the Blueprint §15/§17 compliance system.
 *
 * When an Order is created, if any active ComplianceRule applies to its
 * destination country, a ComplianceCase is opened against the order
 * (status: not_assessed) for staff to work. If no rule applies, no case is
 * created — no rules means nothing to comply with yet.
 *
 * Deliberately never throws: this must never block an order from being
 * created, so any unexpected failure is logged and swallowed.
 */
class OrderObserver
{
    public function created(Order $order): void
    {
        try {
            $countryCode = $this->destinationCountryCode($order);

            if ($countryCode === null) {
                return;
            }

            $hasApplicableRule = ComplianceRule::query()
                ->applicableTo($countryCode)
                ->exists();

            if (! $hasApplicableRule) {
                return;
            }

            ComplianceCase::query()->create([
                'owner_type' => Order::class,
                'owner_id' => $order->id,
                'status' => ComplianceCaseStatus::NotAssessed,
                'country_code' => $countryCode,
                'opened_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('OrderObserver: failed to evaluate/create compliance case for order.', [
                'order_id' => $order->id ?? null,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The order's destination country. Order carries destination_country_code
     * directly (set from the RFQ/shipping details at award time); that is the
     * real destination signal and is used first. If it is ever blank, we fall
     * back to the buyer's country snapshot on the order (buyer_country_code)
     * as the best available proxy for where the goods are headed.
     */
    protected function destinationCountryCode(Order $order): ?string
    {
        $destination = $order->destination_country_code ?? null;

        if (is_string($destination) && trim($destination) !== '') {
            return strtoupper(trim($destination));
        }

        $buyerCountry = $order->buyer_country_code ?? null;

        if (is_string($buyerCountry) && trim($buyerCountry) !== '') {
            return strtoupper(trim($buyerCountry));
        }

        return null;
    }
}
