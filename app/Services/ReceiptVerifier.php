<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Models\Receipt;

/**
 * The public receipt-verification boundary.
 *
 * This class decides two things and nothing else decides them:
 *
 *  1. **How a receipt is found.** By its unguessable 40-char token (what a QR
 *     or printed link carries) or by its printed receipt number. Both lookups
 *     are exact; neither is enumerable in practice, and the endpoint in front
 *     of this is rate-limited so number guessing is not a viable attack.
 *
 *  2. **What a stranger is told.** `publicPayload()` is an allow-list, built by
 *     hand. A third party legitimately needs to know that this platform issued
 *     this document, for this supplier, on this date, for this amount, and
 *     whether it is still good. They need nothing else, so they are given
 *     nothing else: no buyer name, company, email or country; no line items,
 *     specifications or unit prices; no internal notes; no settlement details.
 *
 * Views render from this array only — never from the Receipt or Order models —
 * so a field cannot leak by someone reaching through a relation in Blade.
 */
class ReceiptVerifier
{
    public function findByToken(string $token): ?Receipt
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        return Receipt::with('order.company')->where('verification_token', $token)->first();
    }

    public function findByNumber(string $number): ?Receipt
    {
        $number = strtoupper(trim($number));

        if ($number === '') {
            return null;
        }

        return Receipt::with('order.company')->where('receipt_number', $number)->first();
    }

    /** Accepts either form, so one input box on the page handles both. */
    public function find(string $reference): ?Receipt
    {
        return $this->findByNumber($reference) ?? $this->findByToken($reference);
    }

    /** Records that a check happened. Useful signal; discloses nothing. */
    public function recordCheck(Receipt $receipt): Receipt
    {
        $receipt->forceFill([
            'verified_at' => now(),
            'verification_count' => $receipt->verification_count + 1,
        ])->save();

        return $receipt;
    }

    /**
     * The complete set of facts a verifier is given. Adding a key here is a
     * deliberate disclosure decision, not an implementation detail.
     *
     * @return array<string, mixed>
     */
    public function publicPayload(Receipt $receipt): array
    {
        $order = $receipt->order;

        return [
            'issuer' => config('app.name'),
            'receipt_number' => $receipt->receipt_number,
            'order_reference' => $order->reference_code,
            'issued_at' => $receipt->issued_at,
            'amount' => $receipt->money(),
            'currency' => $receipt->currency->value,
            // The supplier is a publicly listed business on this marketplace,
            // and naming it is the point of a verification. The buyer is not.
            'supplier_name' => $order->supplier_name,
            // Live, deliberately: verification status is a *current* attestation
            // about the supplier, not a historical figure on the document.
            'supplier_verified' => $order->company?->status === CompanyStatus::Verified,
            'order_status' => $order->status->label(),
            'status' => $receipt->verificationStatus(),
            'is_valid' => ! $receipt->isVoid(),
            'void_reason' => $receipt->void_reason,
            'checked_at' => now(),
        ];
    }
}
